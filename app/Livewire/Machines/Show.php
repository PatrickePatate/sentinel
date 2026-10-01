<?php

namespace App\Livewire\Machines;

use App\Ai\ScanRunner;
use App\Livewire\Concerns\AuthorizesAdmin;
use App\Livewire\Concerns\ListensToRealtime;
use App\Livewire\Concerns\StartsScans;
use App\Models\AgentRun;
use App\Models\Machine;
use App\Models\MemorySuggestion;
use App\Ssh\AccessControl;
use App\Ssh\AuditTrail;
use App\Ssh\HostKeyFingerprint;
use App\Ssh\HostKeyPinner;
use App\Ssh\Provisioning\ClientUpdater;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;
use Throwable;

#[Layout('components.layouts.app')]
class Show extends Component
{
    use AuthorizesAdmin;
    use ListensToRealtime;
    use StartsScans;

    public const TABS = ['overview' => 'Overview', 'provisioning' => 'Provisioning', 'chat' => 'Chat', 'scans' => 'Scans', 'activity' => 'Activity'];

    #[Locked]
    public int $machineId;

    #[Url]
    public string $tab = 'overview';

    public string $fingerprint = '';

    /** The key the server presents right now, shown so the admin can compare it with the console of the provider. */
    public ?string $presented = null;

    public function mount(Machine $machine): void
    {
        $this->machineId = $machine->id;
        $this->resetScan();
        abort_unless(array_key_exists($this->tab, self::TABS), 404);
    }

    public function scan(ScanRunner $runner)
    {
        return $this->queueScan($runner, $this->machine());
    }

    protected function scanTarget(): ?Machine
    {
        return $this->machine();
    }

    public function issueLink(): void
    {
        $this->machine()->issueProvisionToken();
        $this->dispatch('toast', message: 'One-hour provisioning link issued', type: 'success');
    }

    public function revokeLink(): void
    {
        $this->machine()->forceFill(['provision_token' => null, 'provision_token_expires_at' => null])->save();
    }

    public function showHostKey(): void
    {
        try {
            $this->presented = HostKeyFingerprint::fetch($this->machine());
        } catch (Throwable $e) {
            report($e);
            $this->presented = null;
        }

        if ($this->presented === null) {
            $this->dispatch('toast', message: 'Could not read the host key', description: 'Is the machine reachable from Sentinel?', type: 'error');
        }
    }

    public function pin(HostKeyPinner $pinner): void
    {
        $this->validate(['fingerprint' => ['required', 'string']]);

        [$status] = $pinner->pinVerified($this->machine(), $this->fingerprint);

        $this->dispatch('toast', ...match ($status) {
            HostKeyPinner::PINNED => ['message' => 'Host key pinned', 'type' => 'success'],
            HostKeyPinner::MISMATCH => ['message' => 'Fingerprint does not match', 'description' => 'The server presents a different key. Nothing was pinned.', 'type' => 'error'],
            default => ['message' => 'Could not reach the machine', 'type' => 'error'],
        });

        $this->fingerprint = '';
    }

    public function checkClient(ClientUpdater $updater): void
    {
        $status = $updater->check($this->machine());

        $this->dispatch('toast', message: $status->label(), description: $status->error ?? '', type: $status->isUpToDate() ? 'success' : ($status->state === 'unreachable' ? 'error' : 'warning'));
    }

    public function updateClient(ClientUpdater $updater): void
    {
        try {
            $status = $updater->deploy($this->machine());
            $this->dispatch('toast', message: 'Client updated', description: $status->installed ?? '', type: 'success');
        } catch (Throwable $e) {
            $this->dispatch('toast', message: 'Update failed', description: $e->getMessage(), type: 'error');
        }
    }

    public function acceptNote(int $id): void
    {
        $machine = $this->machine();
        $suggestion = MemorySuggestion::where('machine_id', $machine->id)->where('status', 'pending')->findOrFail($id);
        $memory = trim($machine->memory."\n- ".$suggestion->note);

        if (mb_strlen($memory) > 3000) {
            $this->dispatch('toast', message: 'The memory is full (3000 characters)', description: 'Shorten it in the machine settings first.', type: 'error');

            return;
        }

        $machine->update(['memory' => $memory]);
        $suggestion->update(['status' => 'accepted']);
        $this->dispatch('toast', message: 'Added to the machine memory', type: 'success');
    }

    public function dismissNote(int $id): void
    {
        MemorySuggestion::where('machine_id', $this->machineId)->where('status', 'pending')->whereKey($id)->update(['status' => 'dismissed']);
    }

    public function revokeTrust(string $action): void
    {
        $machine = $this->machine();
        $machine->forceFill(['trusted_actions' => array_values(array_diff($machine->trusted_actions ?? [], [$action])) ?: null])->save();
        app(AuditTrail::class)->record($machine, null, 'action_trust_revoked', $action, []);
        $this->dispatch('toast', message: 'Back to the normal rules for '.$action);
    }

    public function revoke(AccessControl $access): void
    {
        $cancelled = $access->revoke($this->machine());
        $this->dispatch('toast', message: 'Access revoked', description: "{$cancelled} pending action(s) cancelled. Run the revocation script on the machine too.", type: 'warning');
    }

    public function restore(AccessControl $access): void
    {
        $access->lift($this->machine());
        $this->dispatch('toast', message: 'Revocation lifted: re-provision the machine', type: 'success');
    }

    public function delete()
    {
        $this->machine()->delete();
        session()->flash('toast', ['message' => 'Machine deleted', 'type' => 'success']);

        return $this->redirectRoute('machines.index', navigate: true);
    }

    private function machine(): Machine
    {
        return Machine::findOrFail($this->machineId);
    }

    public function render(ClientUpdater $updater)
    {
        $machine = $this->machine();

        return view('livewire.machines.show', [
            'machine' => $machine,
            'suggestions' => MemorySuggestion::where('machine_id', $machine->id)->where('status', 'pending')->latest('id')->get(),
            'sites' => $machine->siteChecks()->orderBy('url')->get(),
            'disk' => $machine->diskTrend(),
            'client' => $updater->lastKnown($machine),
            'runs' => AgentRun::where('machine_id', $machine->id)->whereNull('parent_run_id')->latest('id')->limit(15)->get(),
            'activity' => $this->tab === 'activity'
                ? Activity::where('log_name', 'ssh')->where('subject_type', $machine->getMorphClass())->where('subject_id', $machine->id)->latest('id')->limit(60)->get()
                : collect(),
            'publicKey' => rescue(fn () => $machine->publicKey(), '(the stored key cannot be read)', false),
        ])->title($machine->name);
    }
}
