<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentRun extends Model
{
    /** @var list<string> */
    protected $fillable = ['machine_id', 'provider', 'objective', 'status', 'messages', 'report', 'trigger', 'severity', 'summary', 'progress'];

    protected function casts(): array
    {
        return ['messages' => 'array'];
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }
}
