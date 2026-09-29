<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;
use RuntimeException;

#[Hidden(['private_key', 'passphrase'])]
class Machine extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['name', 'host', 'port', 'username', 'private_key', 'passphrase', 'host_key_fingerprint', 'environment', 'autonomy_enabled', 'scan_interval_minutes'];

    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
            'passphrase' => 'encrypted',
            'revoked_at' => 'datetime',
            'last_scan_at' => 'datetime',
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

    public function agentRuns(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }
}
