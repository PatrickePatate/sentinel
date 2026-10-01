<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentRun extends Model
{
    /** @var list<string> */
    protected $fillable = ['machine_id', 'parent_run_id', 'provider', 'objective', 'status', 'messages', 'report', 'trigger', 'severity', 'summary', 'progress', 'profile', 'allow_actions', 'model', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'cost_usd'];

    protected function casts(): array
    {
        return ['messages' => 'array', 'allow_actions' => 'boolean', 'cost_usd' => 'float'];
    }

    public function profileLabel(): ?string
    {
        return $this->profile ? (config("sentinel.scheduling.profiles.{$this->profile}.label") ?? $this->profile) : null;
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    /** The scan this follow-up continues. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_run_id');
    }

    /** Follow-up turns asked from this scan's report, oldest first. */
    public function followUps(): HasMany
    {
        return $this->hasMany(self::class, 'parent_run_id')->orderBy('id');
    }

    public function pendingActions(): HasMany
    {
        return $this->hasMany(PendingAction::class);
    }
}
