<?php

namespace App\Sharp\PendingActions;

use App\Models\PendingAction;
use Code16\Sharp\EntityList\Fields\EntityListField;
use Code16\Sharp\EntityList\Fields\EntityListFieldsContainer;
use Code16\Sharp\EntityList\SharpEntityList;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;

class PendingActionList extends SharpEntityList
{
    protected function buildList(EntityListFieldsContainer $fields): void
    {
        $fields
            ->addField(EntityListField::make('created_at')->setLabel('Date'))
            ->addField(EntityListField::make('machine:name')->setLabel('Machine'))
            ->addField(EntityListField::make('command')->setLabel('Command'))
            ->addField(EntityListField::make('risk')->setLabel('Risk'))
            ->addField(EntityListField::make('reason')->setLabel('Why held'))
            ->addField(EntityListField::make('status')->setLabel('Status'));
    }

    public function buildListConfig(): void
    {
        $this->configureDefaultSort('created_at', 'desc');
    }

    public function getInstanceCommands(): ?array
    {
        return [ApprovePendingActionCommand::class, RejectPendingActionCommand::class];
    }

    public function getListData(): array|Arrayable
    {
        return $this
            ->setCustomTransformer('created_at', fn ($value) => $value ? Carbon::parse($value)->format('Y-m-d H:i') : null)
            ->transform(
                PendingAction::with('machine')
                    ->orderByRaw("status = 'pending' desc")
                    ->latest()
                    ->paginate(30)
            );
    }
}
