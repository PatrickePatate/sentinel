<?php

namespace App\Livewire;

use App\Ai\ScanRunner;
use App\Jobs\RunFollowUp;
use App\Livewire\Concerns\AuthorizesAdmin;
use App\Livewire\Concerns\ListensToRealtime;
use App\Models\AgentRun;
use App\Models\Finding;
use App\Models\PendingAction;
use App\Ssh\ActionExecutor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * Live view of a scan. The scan runs on a queue worker (another process), which
 * persists what the agent writes; while the run is active this component polls and re-renders it as Markdown.
 * Once it is finished, the admin can message the agent about it (e.g. "fix what you found"): each message is a
 * follow-up run threaded under the scan, queued and shown live the same way.
 */
#[Layout('components.layouts.app')]
class ScanReport extends Component
{
    use AuthorizesAdmin;
    use ListensToRealtime;

    public const FIX_FINDINGS_MESSAGE = 'Apply the remediation you recommended in your report, using the corrective actions available to you. '
        .'Request each action you are confident about and propose the others for approval. Then report what was executed, what is pending approval and what was refused.';

    #[Locked]
    public int $runId;

    public string $message = '';

    public function mount(AgentRun $run): void
    {
        // A follow-up is always shown within its scan's thread.
        $this->runId = $run->parent_run_id ?? $run->id;
    }

    public function fixFindings(ScanRunner $runner): void
    {
        $this->message = self::FIX_FINDINGS_MESSAGE;
        $this->ask($runner);
    }

    public function ask(ScanRunner $runner): void
    {
        $this->validate(['message' => ['required', 'string', 'max:2000']]);

        $scan = $this->scan();

        if ($scan->isActive() || $scan->followUps->contains(fn (AgentRun $turn) => $turn->isActive())) {
            $this->addError('message', 'Wait for the agent to finish first.');

            return;
        }

        $throttleKey = 'scan:'.auth()->id();

        if (RateLimiter::tooManyAttempts($throttleKey, config('sentinel.limits.scans_per_hour_per_user'))) {
            $this->addError('message', 'Limit reached for this hour. Try again later.');

            return;
        }

        RateLimiter::hit($throttleKey, 3600);

        $followUp = $runner->queueFollowUp($scan, $this->message);
        RunFollowUp::dispatch($followUp->id, auth()->id());

        $this->message = '';
    }

    public function approve(int $id, ActionExecutor $executor): void
    {
        $pending = $this->actionOfThread($id);

        try {
            $executor->approve($pending);
            $this->dispatch('toast', message: 'Action '.$pending->fresh()->status, type: 'success');
        } catch (Throwable $e) {
            report($e);
            $this->dispatch('toast', message: 'Could not run the action', description: $e->getMessage(), type: 'error');
        }
    }

    public function reject(int $id, ActionExecutor $executor): void
    {
        $executor->reject($this->actionOfThread($id));
    }

    private function actionOfThread(int $id): PendingAction
    {
        $runIds = AgentRun::whereKey($this->runId)->orWhere('parent_run_id', $this->runId)->pluck('id');

        return PendingAction::whereIn('agent_run_id', $runIds)->where('status', 'pending')->findOrFail($id);
    }

    private function scan(): AgentRun
    {
        return AgentRun::with(['machine', 'followUps.pendingActions', 'pendingActions'])->findOrFail($this->runId);
    }

    /** @return array<string, Collection<int, Finding>> Findings this scan opened, made worse, resolved or saw again. */
    private function changes(AgentRun $scan): array
    {
        $diff = $scan->findings_diff ?? [];
        $findings = Finding::whereIn('id', collect($diff)->flatten()->all())->get()->keyBy('id');

        return collect(['new', 'escalated', 'resolved', 'ongoing'])
            ->mapWithKeys(fn (string $kind) => [$kind => collect($diff[$kind] ?? [])->map(fn ($id) => $findings->get($id))->filter()->values()])
            ->all();
    }

    public function render()
    {
        $scan = $this->scan();

        $runIds = $scan->followUps->pluck('id')->prepend($scan->id);

        return view('livewire.scan-report', [
            'run' => $scan,
            'steps' => Activity::where('log_name', 'ssh')->whereIn('properties->agent_run_id', $runIds)->whereNotNull('properties->command')->oldest('id')->get(),
            'active' => $scan->isActive() || $scan->followUps->contains(fn (AgentRun $turn) => $turn->isActive()),
            'changes' => $this->changes($scan),
        ])->title('Scan #'.$scan->id);
    }
}
