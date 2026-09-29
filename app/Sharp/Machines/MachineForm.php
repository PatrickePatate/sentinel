<?php

namespace App\Sharp\Machines;

use App\Models\Machine;
use Code16\Sharp\Form\Fields\SharpFormCheckField;
use Code16\Sharp\Form\Fields\SharpFormNumberField;
use Code16\Sharp\Form\Fields\SharpFormSelectField;
use Code16\Sharp\Form\Fields\SharpFormTextareaField;
use Code16\Sharp\Form\Fields\SharpFormTextField;
use Code16\Sharp\Form\Layout\FormLayout;
use Code16\Sharp\Form\Layout\FormLayoutColumn;
use Code16\Sharp\Form\SharpForm;
use Code16\Sharp\Utils\Fields\FieldsContainer;
use Illuminate\Support\Arr;

class MachineForm extends SharpForm
{
    public function buildFormFields(FieldsContainer $formFields): void
    {
        $formFields
            ->addField(SharpFormTextField::make('name')->setLabel('Name')->setMaxLength(100))
            ->addField(SharpFormTextField::make('host')->setLabel('Host')->setMaxLength(255))
            ->addField(SharpFormNumberField::make('port')->setLabel('SSH port')->setMin(1)->setMax(65535))
            ->addField(SharpFormTextField::make('username')->setLabel('SSH user')->setMaxLength(64))
            ->addField(
                SharpFormTextareaField::make('private_key')
                    ->setLabel('Private key')
                    ->setHelpMessage('Write-only. Leave empty to keep the current key. Use a dedicated, unprivileged account.')
                    ->setRowCount(6)
            )
            ->addField(SharpFormTextField::make('passphrase')->setLabel('Key passphrase')->setHelpMessage('Write-only. Leave empty to keep the current one.'))
            ->addField(SharpFormSelectField::make('environment', [
                'production' => 'Production',
                'staging' => 'Staging',
            ])->setLabel('Environment')->setDisplayAsDropdown())
            ->addField(SharpFormCheckField::make('autonomy_enabled', 'Let the agent run low-risk corrective actions on its own (after a Jev risk check)'));
    }

    public function buildFormLayout(FormLayout $formLayout): void
    {
        $formLayout->addColumn(7, fn (FormLayoutColumn $column) => $column
            ->withField('name')
            ->withFields('host|8', 'port|4')
            ->withField('username')
            ->withField('private_key')
            ->withField('passphrase')
            ->withField('environment')
            ->withField('autonomy_enabled')
        );
    }

    public function find(mixed $id): array
    {
        return $this->transform(Machine::findOrFail($id));
    }

    public function update(mixed $id, array $data)
    {
        $machine = $id ? Machine::findOrFail($id) : new Machine;

        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'username' => ['required', 'string', 'max:64'],
            'private_key' => [$id ? 'nullable' : 'required', 'string'],
            'passphrase' => ['nullable', 'string'],
            'environment' => ['required', 'in:production,staging'],
            'autonomy_enabled' => ['boolean'],
        ];

        $this->validate($data, $rules);
        $validated = Arr::only($data, array_keys($rules));

        $hostChanged = $machine->exists && ($machine->host !== $validated['host'] || $machine->port !== (int) $validated['port']);

        foreach (['private_key', 'passphrase'] as $secret) {
            if (blank($validated[$secret] ?? null)) {
                unset($validated[$secret]);
            }
        }

        $machine->fill($validated);

        if ($hostChanged) {
            $machine->host_key_fingerprint = null;
        }

        $machine->save();

        return $machine->id;
    }
}
