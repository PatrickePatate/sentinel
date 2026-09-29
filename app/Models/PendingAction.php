<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingAction extends Model
{
    /** @var list<string> */
    protected $fillable = ['machine_id', 'agent_run_id', 'action', 'arguments', 'command', 'risk', 'reason', 'status', 'output', 'decided_at'];

    protected function casts(): array
    {
        return ['arguments' => 'array', 'decided_at' => 'datetime'];
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }
}
