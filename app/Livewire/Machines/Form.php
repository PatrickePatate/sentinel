<?php

namespace App\Livewire\Machines;

use App\Livewire\Concerns\AuthorizesAdmin;
use App\Models\Machine;
use Closure;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Form extends Component
{
    use AuthorizesAdmin;

    #[Locked]
    public ?int $machineId = null;

    public string $name = '';

    public string $host = '';

    public int $port = 22;

    public string $environment = 'production';

    public int $scan_interval_minutes = 0;

    public bool $webserver_enabled = false;

    public int $webserver_interval_minutes = 0;

    public int $webserver_full_check_hours = 6;

    public string $memory = '';

    public ?string $gate_max_destructive = null;

    public ?string $gate_min_reversible = null;

    public ?string $gate_max_actions = null;

    public bool $autonomy_enabled = false;

    public function mount(?Machine $machine = null): void
    {
        if ($machine?->exists) {
            $this->machineId = $machine->id;
            $this->fill($machine->only(['name', 'host', 'port', 'environment']));
            $this->autonomy_enabled = (bool) $machine->autonomy_enabled;
            $this->scan_interval_minutes = (int) $machine->scan_interval_minutes;
            $this->webserver_enabled = (bool) $machine->webserver_enabled;
            $this->webserver_interval_minutes = (int) $machine->webserver_interval_minutes;
            $this->webserver_full_check_hours = $machine->fullCheckHours();
            $this->memory = (string) $machine->memory;
            $this->gate_max_destructive = $machine->gate_max_destructive === null ? null : (string) $machine->gate_max_destructive;
            $this->gate_min_reversible = $machine->gate_min_reversible === null ? null : (string) $machine->gate_min_reversible;
            $this->gate_max_actions = $machine->gate_max_actions === null ? null : (string) $machine->gate_max_actions;
        }
    }

    public function save()
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'host' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail) {
                $isHostname = preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i', (string) $value);

                if (! $isHostname && ! filter_var($value, FILTER_VALIDATE_IP)) {
                    $fail('The host must be an IP address or a valid hostname.');
                }
            }],
            'port' => ['required', 'integer', 'between:1,65535'],
            'environment' => ['required', 'in:production,staging'],
            'autonomy_enabled' => ['boolean'],
            'scan_interval_minutes' => ['nullable', 'integer', Rule::in(array_keys(Machine::scanProfiles()['audit']['frequencies']))],
            'webserver_enabled' => ['boolean'],
            'webserver_full_check_hours' => ['required', 'integer', Rule::in(array_keys(config('sentinel.scheduling.precheck.full_check_choices')))],
            'webserver_interval_minutes' => ['nullable', 'integer', Rule::in(array_keys(Machine::scanProfiles()['webserver']['frequencies']))],
            'memory' => ['nullable', 'string', 'max:3000'],
            // Per-machine tuning of the risk gate. Bounded: a machine can be made stricter freely, but not close to reckless.
            'gate_max_destructive' => ['nullable', 'numeric', 'between:0,0.2'],
            'gate_min_reversible' => ['nullable', 'numeric', 'between:0.5,1'],
            'gate_max_actions' => ['nullable', 'integer', 'between:0,10'],
        ]);

        $machine = $this->machineId ? Machine::findOrFail($this->machineId) : new Machine;
        $hostChanged = $machine->exists && ($machine->host !== $data['host'] || $machine->port !== (int) $data['port']);

        $data['scan_interval_minutes'] = ((int) $data['scan_interval_minutes']) ?: null;
        $data['webserver_interval_minutes'] = ((int) $data['webserver_interval_minutes']) ?: null;
        $data['memory'] = trim((string) $data['memory']) ?: null;

        foreach (['gate_max_destructive', 'gate_min_reversible', 'gate_max_actions'] as $key) {
            $data[$key] = ($data[$key] ?? '') === '' ? null : $data[$key] + 0;
        }
        $machine->fill($data);

        if (! $machine->exists) {
            // Sentinel owns the credentials: a dedicated account name and a fresh key, deployed by the provisioning script.
            $machine->username = config('sentinel.provisioning.user');
            $machine->private_key = Machine::generatePrivateKey();
        }

        if ($hostChanged) {
            $machine->host_key_fingerprint = null;
        }

        $isNew = ! $machine->exists;
        $machine->save();

        session()->flash('toast', ['message' => $isNew ? 'Machine created: now provision it' : 'Machine saved', 'type' => 'success']);

        return $this->redirectRoute('machines.show', array_filter(['machine' => $machine, 'tab' => $isNew ? 'provisioning' : null]), navigate: true);
    }

    public function render()
    {
        return view('livewire.machines.form', [
            'title' => $this->machineId ? 'Edit machine' : 'New machine',
            'profiles' => Machine::scanProfiles(),
            'fullCheckChoices' => config('sentinel.scheduling.precheck.full_check_choices'),
        ])->title($this->machineId ? 'Edit machine' : 'New machine');
    }
}
