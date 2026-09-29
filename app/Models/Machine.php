<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

#[Hidden(['private_key', 'passphrase'])]
class Machine extends Model
{
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['name', 'host', 'port', 'username', 'private_key', 'passphrase', 'host_key_fingerprint', 'environment', 'autonomy_enabled'];

    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
            'passphrase' => 'encrypted',
            'revoked_at' => 'datetime',
        ];
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
