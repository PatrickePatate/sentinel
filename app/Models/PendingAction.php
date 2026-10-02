<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property array<string, mixed>|null $arguments
 * @property Carbon|null $decided_at
 * @property Carbon|null $run_after
 */ class PendingAction extends Model
{
    /** @var list<string> */
    protected $fillable = ['machine_id', 'agent_run_id', 'action', 'arguments', 'command', 'risk', 'reason', 'status', 'output', 'decided_at', 'run_after', 'approvals'];

    protected function casts(): array
    {
        return ['arguments' => 'array', 'decided_at' => 'datetime', 'run_after' => 'datetime', 'approvals' => 'array'];
    }

    /** @return BelongsTo<Machine, $this> */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    /** @return BelongsTo<AgentRun, $this> */
    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }
}
