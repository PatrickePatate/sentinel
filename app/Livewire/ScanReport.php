<?php

namespace App\Livewire;

use App\Models\AgentRun;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Live view of a scan, embedded in the Sharp show page. The scan runs on a queue worker (another process), which
 * persists what the agent writes; while the run is active this component polls and re-renders it as Markdown.
 */
#[Layout('layouts.chat')]
class ScanReport extends Component
{
    #[Locked]
    public int $runId;

    public function mount(AgentRun $run): void
    {
        $this->runId = $run->id;
    }

    public function render()
    {
        return view('livewire.scan-report', ['run' => AgentRun::with('machine')->findOrFail($this->runId)]);
    }
}
