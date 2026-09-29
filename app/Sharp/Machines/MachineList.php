<?php

namespace App\Sharp\Machines;

use App\Models\Machine;
use Code16\Sharp\EntityList\Fields\EntityListField;
use Code16\Sharp\EntityList\Fields\EntityListFieldsContainer;
use Code16\Sharp\EntityList\SharpEntityList;
use Illuminate\Contracts\Support\Arrayable;

class MachineList extends SharpEntityList
{
    protected function buildList(EntityListFieldsContainer $fields): void
    {
        $fields
            ->addField(EntityListField::make('name')->setLabel('Name')->setHtml(false)->setSortable())
            ->addField(EntityListField::make('address')->setLabel('Address')->setHtml(false))
            ->addField(EntityListField::make('environment')->setLabel('Environment')->setHtml(false))
            ->addField(EntityListField::make('host_key')->setLabel('Host key')->setHtml(false))
            ->addField(EntityListField::make('autonomy')->setLabel('Autonomy')->setHtml(false));
    }

    public function buildListConfig(): void
    {
        $this
            ->configureSearchable()
            ->configureDefaultSort('name', 'asc')
            ->configureDelete(confirmationText: 'Delete this machine and its history?');
    }

    public function getInstanceCommands(): ?array
    {
        return [ScanMachineCommand::class];
    }

    public function getListData(): array|Arrayable
    {
        $machines = Machine::query()
            ->when($this->queryParams->hasSearch(), function ($query) {
                foreach ($this->queryParams->searchWords() as $word) {
                    $query->where('name', 'like', $word);
                }
            })
            ->orderBy($this->queryParams->sortedBy() ?: 'name', $this->queryParams->sortedDir() ?: 'asc');

        return $this
            ->setCustomTransformer('address', fn ($value, Machine $machine) => "{$machine->username}@{$machine->host}:{$machine->port}")
            ->setCustomTransformer('host_key', fn ($value, Machine $machine) => $machine->host_key_fingerprint ? 'pinned' : 'NOT PINNED')
            ->setCustomTransformer('autonomy', fn ($value, Machine $machine) => $machine->autonomy_enabled ? 'enabled' : 'off')
            ->transform($machines->paginate(30));
    }
}
