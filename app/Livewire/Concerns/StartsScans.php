<?php

namespace App\Livewire\Concerns;

use App\Ai\ScanRunner;
use App\Jobs\RunScan;
use App\Models\Machine;
use Illuminate\Support\Facades\RateLimiter;

/** The "Run an AI scan" modal: a scan type (profile) that presets the objective, which stays editable. */
trait StartsScans
{
    public string $profile = 'audit';

    public string $objective = '';

    public bool $allowActions = false;

    public function resetScan(): void
    {
        $this->profile = 'audit';
        $this->allowActions = false;
        $this->objective = config('sentinel.scheduling.profiles.audit.objective');
    }

    public function updatedProfile(): void
    {
        $this->objective = config("sentinel.scheduling.profiles.{$this->profile}.objective") ?? $this->objective;
    }

    /** @return array<string, string> scan type => label, for the machine the modal is about */
    public function scanTypes(): array
    {
        $machine = $this->scanTarget();

        return collect($machine ? $machine->availableScanProfiles() : Machine::scanProfiles())->map(fn ($p) => $p['label'])->all();
    }

    abstract protected function scanTarget(): ?Machine;

    protected function queueScan(ScanRunner $runner, Machine $machine)
    {
        $this->validate([
            'profile' => ['required', 'in:'.implode(',', array_keys($machine->availableScanProfiles()))],
            'objective' => ['required', 'string', 'max:1000'],
            'allowActions' => ['boolean'],
        ]);

        $key = 'scan:'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, config('sentinel.limits.scans_per_hour_per_user'))) {
            $this->dispatch('toast', message: 'Scan limit reached for this hour', type: 'error');

            return null;
        }

        RateLimiter::hit($key, 3600);

        $run = $runner->queue($machine, $this->objective, 'manual', $this->profile, $this->allowActions && ! $machine->autonomy_enabled);
        RunScan::dispatch($machine->id, $this->objective, auth()->id(), 'manual', $run->id, $this->profile);

        return $this->redirectRoute('scans.show', $run, navigate: true);
    }
}
