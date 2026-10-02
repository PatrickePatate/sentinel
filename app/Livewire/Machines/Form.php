<?php

namespace App\Livewire\Machines;

use App\Livewire\Concerns\AuthorizesAccess;
use App\Models\Machine;
use Closure;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Form extends Component
{
    use AuthorizesAccess;

    protected function requiredAbility(): string
    {
        return 'admin';
    }

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

    public bool $autonomy_medium = false;

    public bool $two_person_approval = false;

    public ?string $monthly_budget_usd = null;

    /** @var list<int> ISO weekdays of the maintenance window. */
    public array $maintenance_days = [];

    public string $maintenance_start = '03:00';

    public int $maintenance_minutes = 120;

    public const WINDOW_LENGTHS = [30 => '30 minutes', 60 => '1 hour', 120 => '2 hours', 240 => '4 hours', 480 => '8 hours'];

    public function mount(?Machine $machine = null): void
    {
        if ($machine?->exists) {
            $this->machineId = $machine->id;
            $this->fill($machine->only(['name', 'host', 'port', 'environment']));
            $this->autonomy_enabled = (bool) $machine->autonomy_enabled;
            $this->autonomy_medium = (bool) $machine->autonomy_medium;
            $this->two_person_approval = (bool) $machine->two_person_approval;
            $this->monthly_budget_usd = $machine->monthly_budget_usd === null ? null : (string) $machine->monthly_budget_usd;
            $this->scan_interval_minutes = (int) $machine->scan_interval_minutes;
            $this->webserver_enabled = (bool) $machine->webserver_enabled;
            $this->webserver_interval_minutes = (int) $machine->webserver_interval_minutes;
            $this->webserver_full_check_hours = $machine->fullCheckHours();
            $this->memory = (string) $machine->memory;
            $this->gate_max_destructive = $machine->gate_max_destructive === null ? null : (string) $machine->gate_max_destructive;
            $this->gate_min_reversible = $machine->gate_min_reversible === null ? null : (string) $machine->gate_min_reversible;
            $this->gate_max_actions = $machine->gate_max_actions === null ? null : (string) $machine->gate_max_actions;
            $this->maintenance_days = array_map('intval', $machine->maintenance_days ?? []);
            $this->maintenance_start = $machine->maintenance_start ?? '03:00';
            $this->maintenance_minutes = $machine->maintenance_minutes ?? 120;
        }
    }

    public function save()
    {
        $data = $this->validate([
            // No control characters: the name ends up in the provisioning script, which runs as root.
            'name' => ['required', 'string', 'max:100', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
            'host' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail) {
                $isHostname = preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/i', (string) $value);

                if (! $isHostname && ! filter_var($value, FILTER_VALIDATE_IP)) {
                    $fail('The host must be an IP address or a valid hostname.');
                }
            }],
            'port' => ['required', 'integer', 'between:1,65535'],
            'environment' => ['required', 'in:production,staging'],
            'autonomy_enabled' => ['boolean'],
            'autonomy_medium' => ['boolean'],
            'two_person_approval' => ['boolean'],
            'monthly_budget_usd' => ['nullable', 'numeric', 'between:0,100000'],
            'scan_interval_minutes' => ['nullable', 'integer', Rule::in(array_keys(Machine::scanProfiles()['audit']['frequencies']))],
            'webserver_enabled' => ['boolean'],
            'webserver_full_check_hours' => ['required', 'integer', Rule::in(array_keys(config('sentinel.scheduling.precheck.full_check_choices')))],
            'webserver_interval_minutes' => ['nullable', 'integer', Rule::in(array_keys(Machine::scanProfiles()['webserver']['frequencies']))],
            'memory' => ['nullable', 'string', 'max:3000'],
            // Per-machine tuning of the risk gate. Bounded: a machine can be made stricter freely, but not close to reckless.
            'gate_max_destructive' => ['nullable', 'numeric', 'between:0,0.2'],
            'gate_min_reversible' => ['nullable', 'numeric', 'between:0.5,1'],
            'gate_max_actions' => ['nullable', 'integer', 'between:0,10'],
            'maintenance_days' => ['array'],
            'maintenance_days.*' => ['integer', 'between:1,7', 'distinct'],
            'maintenance_start' => ['required', 'date_format:H:i'],
            'maintenance_minutes' => ['required', 'integer', Rule::in(array_keys(self::WINDOW_LENGTHS))],
        ]);

        // No day picked: no window at all.
        $data['maintenance_days'] = array_values(array_map('intval', $data['maintenance_days'])) ?: null;

        // Moderate actions only make sense on top of autonomy itself.
        $data['autonomy_medium'] = $data['autonomy_enabled'] && $data['autonomy_medium'];

        $machine = $this->machineId ? Machine::findOrFail($this->machineId) : new Machine;
        $hostChanged = $machine->exists && ($machine->host !== $data['host'] || $machine->port !== (int) $data['port']);

        $data['scan_interval_minutes'] = ((int) $data['scan_interval_minutes']) ?: null;
        $data['webserver_interval_minutes'] = ((int) $data['webserver_interval_minutes']) ?: null;
        $data['memory'] = trim((string) $data['memory']) ?: null;

        foreach (['gate_max_destructive', 'gate_min_reversible', 'gate_max_actions', 'monthly_budget_usd'] as $key) {
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
