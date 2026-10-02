<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;
use RuntimeException;

/**
 * @property list<int|string>|null $maintenance_days
 * @property Carbon|null $maintenance_until
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_scan_at
 * @property Carbon|null $last_webserver_scan_at
 * @property Carbon|null $provision_token_expires_at
 * @property Carbon|null $provisioned_at
 * @property Carbon|null $client_checked_at
 * @property list<string>|null $trusted_actions
 */
#[Hidden(['private_key', 'passphrase', 'provision_token'])]
class Machine extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['name', 'host', 'port', 'username', 'private_key', 'passphrase', 'host_key_fingerprint', 'environment', 'autonomy_enabled', 'autonomy_medium', 'scan_interval_minutes', 'webserver_interval_minutes', 'webserver_enabled', 'webserver_full_check_hours', 'memory', 'gate_max_destructive', 'gate_min_reversible', 'gate_max_actions', 'maintenance_days', 'maintenance_start', 'maintenance_minutes', 'maintenance_until'];

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
            'autonomy_enabled' => 'boolean',
            'autonomy_medium' => 'boolean',
            'maintenance_days' => 'array',
            'maintenance_until' => 'datetime',
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
        return $this->metricTrend('disk_root_percent', 14);
    }

    /**
     * Where a metric stands and where it is heading: a least-squares line over the window, so one odd sample
     * does not swing the forecast. days_left is when a percentage would reach 100 at this pace.
     *
     * @return array{percent: float, since: CarbonInterface, from: float, per_day: float, days_left: ?int}|null
     */
    public function metricTrend(string $name, int $days = 7): ?array
    {
        $samples = MachineMetric::where('machine_id', $this->id)->where('name', $name)
            ->where('recorded_at', '>=', now()->subDays($days))->orderBy('recorded_at')->get();

        if ($samples->count() < 2) {
            return null;
        }

        $start = $samples->first()->recorded_at;
        $points = $samples->map(fn (MachineMetric $m) => [$start->diffInSeconds($m->recorded_at) / 86400, $m->value]);
        $meanX = $points->avg(0);
        $meanY = $points->avg(1);
        $variance = $points->sum(fn ($p) => ($p[0] - $meanX) ** 2);
        $perDay = $variance > 0 ? $points->sum(fn ($p) => ($p[0] - $meanX) * ($p[1] - $meanY)) / $variance : 0.0;
        $last = $samples->last()->value;

        return [
            'percent' => $last,
            'since' => $start,
            'from' => $samples->first()->value,
            'per_day' => $perDay,
            'days_left' => $perDay > 0.05 && $last < 100 ? (int) ceil((100 - $last) / $perDay) : null,
        ];
    }

    /** @return array<string, float> Latest value of each metric. */
    public function latestMetrics(): array
    {
        return MachineMetric::where('machine_id', $this->id)->where('recorded_at', '>=', now()->subDay())
            ->orderBy('recorded_at')->get()->mapWithKeys(fn (MachineMetric $m) => [$m->name => $m->value])->all();
    }

    public function hasMaintenanceWindow(): bool
    {
        return filled($this->maintenance_days) && preg_match('/^\d{2}:\d{2}$/', (string) $this->maintenance_start) && $this->maintenance_minutes > 0;
    }

    /**
     * The maintenance window in progress or the next one, as [start, end], in the application timezone.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}|null
     */
    public function maintenanceWindow(?CarbonInterface $at = null): ?array
    {
        if (! $this->hasMaintenanceWindow()) {
            return null;
        }

        $at ??= now();
        [$hour, $minute] = array_map('intval', explode(':', $this->maintenance_start));

        // From yesterday (a window that started before midnight may still be open) up to a week ahead.
        foreach (range(-1, 7) as $offset) {
            $start = $at->copy()->startOfDay()->addDays($offset)->setTime($hour, $minute);
            $end = $start->copy()->addMinutes($this->maintenance_minutes);

            if (in_array($start->isoWeekday(), array_map('intval', $this->maintenance_days), true) && $end->gt($at)) {
                return [$start, $end];
            }
        }

        return null;
    }

    public function inMaintenanceWindow(?CarbonInterface $at = null): bool
    {
        $window = $this->maintenanceWindow($at);

        return $window !== null && $window[0]->lte($at ?? now());
    }

    public function underPlannedWork(): bool
    {
        return $this->maintenance_until?->isFuture() ?? false;
    }

    /** During planned work or a maintenance window, things breaking for a moment is expected: alerts stay quiet. */
    public function isQuiet(): bool
    {
        return $this->underPlannedWork() || $this->inMaintenanceWindow();
    }

    /** @return HasMany<SiteCheck, $this> */
    public function siteChecks(): HasMany
    {
        return $this->hasMany(SiteCheck::class);
    }

    /** @return HasMany<MemorySuggestion, $this> */
    public function memorySuggestions(): HasMany
    {
        return $this->hasMany(MemorySuggestion::class);
    }

    /** @return HasMany<Finding, $this> */
    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class);
    }

    /** @return HasMany<AgentRun, $this> */
    public function agentRuns(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }
}
