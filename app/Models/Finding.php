<?php

namespace App\Models;

use App\Ai\Severity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One problem on a machine, followed across scans: opened when a scan first reports it, kept open while
 * later scans of the same profile report it again, resolved when one of them no longer does.
 *
 * @property Carbon|null $muted_until
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $resolved_at
 */
class Finding extends Model
{
    public const STATUSES = ['open', 'acknowledged', 'muted', 'resolved'];

    /** @var list<string> */
    protected $fillable = ['machine_id', 'profile', 'key', 'title', 'severity', 'evidence', 'status', 'muted_until', 'first_seen_run_id', 'last_seen_run_id', 'resolved_run_id', 'first_seen_at', 'last_seen_at', 'resolved_at', 'occurrences'];

    protected function casts(): array
    {
        return ['muted_until' => 'datetime', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function severityLevel(): Severity
    {
        return Severity::tryFrom($this->severity) ?? Severity::Medium;
    }

    public function isMuted(): bool
    {
        return $this->status === 'muted' && $this->muted_until?->isFuture();
    }

    /**
     * Not resolved: still there on the machine, whether someone already looked at it or not.
     *
     * @param  Builder<self>  $query
     */
    public function scopeUnresolved(Builder $query): void
    {
        $query->where('status', '!=', 'resolved');
    }

    /** @return BelongsTo<Machine, $this> */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    /** @return BelongsTo<AgentRun, $this> */
    public function firstSeenRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'first_seen_run_id');
    }

    /** @return BelongsTo<AgentRun, $this> */
    public function lastSeenRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'last_seen_run_id');
    }
}
