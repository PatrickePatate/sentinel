<?php

namespace App\Livewire\Sites;

use App\Livewire\Concerns\AuthorizesAdmin;
use App\Livewire\Concerns\ListensToRealtime;
use App\Models\Machine;
use App\Models\SiteCheck;
use App\Monitoring\SiteMonitor;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['title' => 'Sites'])]
class Index extends Component
{
    use AuthorizesAdmin;
    use ListensToRealtime;

    public ?int $machine_id = null;

    public string $url = '';

    public bool $analyze_on_down = true;

    public function add(SiteMonitor $monitor): void
    {
        $data = $this->validate([
            'machine_id' => ['required', 'integer', 'exists:machines,id'],
            'url' => ['required', 'string', 'max:500', 'url:http,https', 'regex:#^https?://[^\s/]+(/\S*)?$#i'],
            'analyze_on_down' => ['boolean'],
        ], attributes: ['machine_id' => 'machine']);

        $machine = Machine::findOrFail($data['machine_id']);
        $url = trim($data['url']);

        if (SiteCheck::where('machine_id', $machine->id)->where('url', $url)->exists()) {
            $this->addError('url', 'This site is already monitored on that machine.');

            return;
        }

        if (SiteCheck::where('machine_id', $machine->id)->count() >= SiteMonitor::MAX_PER_MACHINE) {
            $this->addError('url', 'At most '.SiteMonitor::MAX_PER_MACHINE.' sites per machine.');

            return;
        }

        $site = SiteCheck::create(['machine_id' => $machine->id, 'url' => $url, 'analyze_on_down' => $data['analyze_on_down']]);
        $monitor->check($site);

        $this->reset('url');
        $this->dispatch('close-modal');
        $this->dispatch('toast', message: 'Site added', description: $site->fresh()->ok ? 'It answers.' : 'It does not answer right now.', type: $site->fresh()->ok ? 'success' : 'warning');
    }

    public function checkNow(int $id, SiteMonitor $monitor): void
    {
        $site = $monitor->check(SiteCheck::with('machine')->findOrFail($id));
        $this->dispatch('toast', message: $site->ok ? 'The site answers' : 'The site is down', description: $site->ok ? "HTTP {$site->status_code}, {$site->response_ms} ms" : (string) $site->error, type: $site->ok ? 'success' : 'error');
    }

    public function toggleAnalysis(int $id): void
    {
        $site = SiteCheck::findOrFail($id);
        $site->update(['analyze_on_down' => ! $site->analyze_on_down]);
    }

    public function remove(int $id): void
    {
        SiteCheck::whereKey($id)->delete();
        $this->dispatch('toast', message: 'Site removed');
    }

    public function render(SiteMonitor $monitor)
    {
        $sites = SiteCheck::with('machine')->orderByRaw('ok asc')->orderBy('url')->get();

        return view('livewire.sites.index', [
            'sites' => $sites,
            'machines' => Machine::whereNull('revoked_at')->orderBy('name')->pluck('name', 'id'),
            'willAnalyze' => $sites->mapWithKeys(fn (SiteCheck $s) => [$s->id => $monitor->willAnalyze($s->machine, $s)]),
        ]);
    }
}
