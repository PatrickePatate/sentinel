<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;
use RuntimeException;

#[Hidden(['private_key', 'passphrase', 'provision_token'])]
class Machine extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['name', 'host', 'port', 'username', 'private_key', 'passphrase', 'host_key_fingerprint', 'environment', 'autonomy_enabled', 'scan_interval_minutes', 'webserver_interval_minutes', 'webserver_enabled', 'webserver_full_check_hours', 'memory', 'gate_max_destructive', 'gate_min_reversible', 'gate_max_actions'];

    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
            'passphrase' => 'encrypted',
            'revoked_at' => 'datetime',
            'last_scan_at' => 'datetime',
            'last_webserver_scan_at' => 'datetime',
            'provision_token' => 'encrypted',
            'provision_token_expires_at' => 'datetime',
            'provisioned_at' => 'datetime',
            'client_checked_at' => 'datetime',
            'host_keys_reported' => 'array',
            'trusted_actions' => 'array',
            'webserver_enabled' => 'boolean',
        ];
    }

    /** OpenSSH public half of the key Sentinel generated (or was given) for this machine. */
    public function publicKey(): string
    {
        $key = PublicKeyLoader::load($this->private_key, $this->passphrase ?: false);

        return trim($key->getPublicKey()->toString('OpenSSH', ['comment' => 'sentinel']));
    }

    /** A fresh Ed25519 private key in OpenSSH format. It never leaves Sentinel: only the public half is deployed. */
    public static function generatePrivateKey(): string
    {
        return EC::createKey('Ed25519')->toString('OpenSSH');
    }

    /** A fresh secret for the one-line provisioning URL; any earlier one stops working. */
    public function issueProvisionToken(int $ttlMinutes = 60): string
    {
        $token = Str::random(40);
        $this->forceFill(['provision_token' => $token, 'provision_token_expires_at' => now()->addMinutes($ttlMinutes)])->save();

        return $token;
    }

    public function acceptsProvisionToken(string $token): bool
    {
        return $this->provision_token !== null
            && $this->provision_token_expires_at?->isFuture()
            && ! $this->isRevoked()
            && hash_equals($this->provision_token, $token);
    }

    public function provisionUrl(): ?string
    {
        return $this->hasProvisionToken()
            ? rtrim(config('app.url'), '/')."/provision/{$this->id}/{$this->provision_token}"
            : null;
    }

    public function hasProvisionToken(): bool
    {
        return $this->provision_token !== null && $this->provision_token_expires_at?->isFuture();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** Kill switch: every SSH transport calls this before opening a connection. */
    public function assertActive(): void
    {
        if ($this->isRevoked()) {
            throw new RuntimeException("Access to {$this->name} was revoked on {$this->revoked_at}.");
        }
    }

    /** Scan profiles (config sentinel.scheduling.profiles) with their frequency list resolved. */
    public static function scanProfiles(): array
    {
        return array_map(
            fn (array $profile) => array_filter($profile, fn ($v) => $v !== null) + ['frequencies' => config('sentinel.scheduling.frequencies')],
            config('sentinel.scheduling.profiles'),
        );
    }

    /** Scan types this machine may run: the web server analysis only where an admin switched it on. */
    public function availableScanProfiles(): array
    {
        return array_filter(self::scanProfiles(), fn (array $profile) => ! isset($profile['enabled_column']) || $this->{$profile['enabled_column']});
    }

    /** Hours between full AI checks when the plain check is healthy: the machine's own setting, else the global default; 0 = never. */
    public function fullCheckHours(): int
    {
        return (int) ($this->webserver_full_check_hours ?? config('sentinel.scheduling.precheck.full_check_hours'));
    }

    /** Configured frequency (minutes) of a scan profile, null when it is manual only. */
    public function scanInterval(string $profile): ?int
    {
        $minutes = (int) $this->{config("sentinel.scheduling.profiles.{$profile}.interval_column")};

        return $minutes > 0 ? $minutes : null;
    }

    /** Risk gate setting for this machine: its own override when set, the global one otherwise. */
    public function gate(string $key): float|int
    {
        $config = ['max_destructive' => 'max_destructive', 'min_reversible' => 'min_reversible', 'max_actions' => 'max_autonomous_actions_per_run'][$key];

        return $this->{"gate_{$key}"} ?? config("sentinel.gate.{$config}");
    }

    public function trusts(string $action): bool
    {
        return in_array($action, $this->trusted_actions ?? [], true);
    }

    /** @return array{percent: float, since: CarbonInterface, from: float, per_day: float, days_left: ?int}|null */
    public function diskTrend(): ?array
    {
        $samples = MachineMetric::where('machine_id', $this->id)->where('name', 'disk_root_percent')
            ->where('recorded_at', '>=', now()->subDays(14))->orderBy('recorded_at')->get();

        if ($samples->count() < 2) {
            return null;
        }

        [$first, $last] = [$samples->first(), $samples->last()];
        $days = max($first->recorded_at->diffInSeconds($last->recorded_at) / 86400, 0.01);
        $perDay = ($last->value - $first->value) / $days;

        return ['percent' => $last->value, 'since' => $first->recorded_at, 'from' => $first->value, 'per_day' => $perDay, 'days_left' => $perDay > 0.05 ? (int) ceil((100 - $last->value) / $perDay) : null];
    }

    public function siteChecks(): HasMany
    {
        return $this->hasMany(SiteCheck::class);
    }

    public function memorySuggestions(): HasMany
    {
        return $this->hasMany(MemorySuggestion::class);
    }

    public function agentRuns(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }
}
