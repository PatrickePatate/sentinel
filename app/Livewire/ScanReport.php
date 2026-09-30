<?php

namespace App\Livewire;

use App\Ai\ScanRunner;
use App\Jobs\RunFollowUp;
use App\Models\AgentRun;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Live view of a scan, embedded in the Sharp show page. The scan runs on a queue worker (another process), which
 * persists what the agent writes; while the run is active this component polls and re-renders it as Markdown.
 * Once it is finished, the admin can message the agent about it (e.g. "fix what you found"): each message is a
 * follow-up run threaded under the scan, queued and shown live the same way.
 */
#[Layout('layouts.chat')]
class ScanReport extends Component
{
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

    private function scan(): AgentRun
    {
        return AgentRun::with(['machine', 'followUps.pendingActions', 'pendingActions'])->findOrFail($this->runId);
    }

    public function render()
    {
        $scan = $this->scan();

        return view('livewire.scan-report', [
            'run' => $scan,
            'active' => $scan->isActive() || $scan->followUps->contains(fn (AgentRun $turn) => $turn->isActive()),
        ]);
    }
}
