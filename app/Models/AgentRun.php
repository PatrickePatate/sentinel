<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<string, array{title: string, severity: string, evidence: string}>|null $reported_findings
 * @property array{new: list<int>, escalated: list<int>, resolved: list<int>, ongoing: list<int>}|null $findings_diff
 */
class AgentRun extends Model
{
    /** @var list<string> */
    protected $fillable = ['machine_id', 'parent_run_id', 'provider', 'objective', 'status', 'messages', 'report', 'trigger', 'severity', 'summary', 'progress', 'profile', 'allow_actions', 'model', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens', 'cost_usd', 'reported_findings', 'findings_diff', 'state_fingerprint'];

    protected function casts(): array
    {
        return ['messages' => 'array', 'allow_actions' => 'boolean', 'cost_usd' => 'float', 'reported_findings' => 'array', 'findings_diff' => 'array'];
    }

    public function profileLabel(): ?string
    {
        return $this->profile ? (config("sentinel.scheduling.profiles.{$this->profile}.label") ?? $this->profile) : null;
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }

    /** @return BelongsTo<Machine, $this> */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    /** The scan this follow-up continues. */
    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_run_id');
    }

    /** Follow-up turns asked from this scan's report, oldest first. */
    /** @return HasMany<self, $this> */
    public function followUps(): HasMany
    {
        return $this->hasMany(self::class, 'parent_run_id')->orderBy('id');
    }

    /** @return HasMany<PendingAction, $this> */
    public function pendingActions(): HasMany
    {
        return $this->hasMany(PendingAction::class);
    }
}
