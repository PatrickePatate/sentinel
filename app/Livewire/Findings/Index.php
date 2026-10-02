<?php

namespace App\Livewire\Findings;

use App\Livewire\Concerns\AuthorizesAccess;
use App\Livewire\Concerns\ListensToRealtime;
use App\Models\Finding;
use App\Models\Machine;
use App\Ssh\AuditTrail;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Problems found by scans across every machine, followed until a scan no longer sees them. */
#[Layout('components.layouts.app', ['title' => 'Issues'])]
class Index extends Component
{
    use AuthorizesAccess, ListensToRealtime, WithPagination;

    public const MUTE_DAYS = [7, 30, 90];

    // "unresolved" (default) or one of Finding::STATUSES.
    #[Url]
    public string $status = 'unresolved';

    #[Url]
    public string $machine = '';

    #[Url]
    public string $severity = '';

    public function updating(): void
    {
        $this->resetPage();
    }

    public function acknowledge(int $id): void
    {
        $this->allow('approve');
        $this->change($id, ['status' => 'acknowledged', 'muted_until' => null], 'finding_acknowledged');
    }

    public function mute(int $id, int $days): void
    {
        $this->allow('approve');
        abort_unless(in_array($days, self::MUTE_DAYS, true), 422);

        $this->change($id, ['status' => 'muted', 'muted_until' => now()->addDays($days)], 'finding_muted', ['days' => $days]);
    }

    /** Back to open, e.g. after an acknowledgement or a mute that was a mistake. */
    public function reopen(int $id): void
    {
        $this->allow('approve');
        $this->change($id, ['status' => 'open', 'muted_until' => null, 'resolved_at' => null, 'resolved_run_id' => null], 'finding_reopened');
    }

    /** Fixed by hand: the next scan reopens it if it is still there. */
    public function resolve(int $id): void
    {
        $this->allow('approve');
        $this->change($id, ['status' => 'resolved', 'muted_until' => null, 'resolved_at' => now(), 'resolved_run_id' => null], 'finding_resolved');
    }

    /** @param array<string, mixed> $attributes */
    private function change(int $id, array $attributes, string $event, array $context = []): void
    {
        $finding = Finding::with('machine')->findOrFail($id);
        $finding->update($attributes);
        app(AuditTrail::class)->record($finding->machine, null, $event, $finding->title, ['finding_id' => $finding->id, 'key' => $finding->key] + $context);
    }

    public function render()
    {
        $findings = Finding::with('machine')
            ->when($this->status === 'unresolved', fn ($q) => $q->unresolved())
            ->when(in_array($this->status, Finding::STATUSES, true), fn ($q) => $q->where('status', $this->status))
            ->when($this->machine !== '', fn ($q) => $q->where('machine_id', $this->machine))
            ->when($this->severity !== '', fn ($q) => $q->where('severity', $this->severity))
            ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 when 'medium' then 2 when 'low' then 3 else 4 end")
            ->latest('last_seen_at')
            ->paginate(25);

        return view('livewire.findings.index', [
            'findings' => $findings,
            'machines' => Machine::orderBy('name')->pluck('name', 'id'),
            'counts' => Finding::unresolved()->selectRaw('severity, count(*) as n')->groupBy('severity')->pluck('n', 'severity'),
        ]);
    }
}
