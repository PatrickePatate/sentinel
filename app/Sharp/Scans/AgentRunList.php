<?php

namespace App\Sharp\Scans;

use App\Models\AgentRun;
use Code16\Sharp\EntityList\Fields\EntityListField;
use Code16\Sharp\EntityList\Fields\EntityListFieldsContainer;
use Code16\Sharp\EntityList\Fields\EntityListStateField;
use Code16\Sharp\EntityList\SharpEntityList;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;

class AgentRunList extends SharpEntityList
{
    protected function buildList(EntityListFieldsContainer $fields): void
    {
        $fields
            ->addField(EntityListField::make('created_at')->setLabel('Date')->setHtml(false)->setSortable())
            ->addField(EntityListField::make('machine:name')->setLabel('Machine')->setHtml(false))
            ->addField(EntityListField::make('objective')->setLabel('Objective')->setHtml(false))
            ->addField(EntityListField::make('provider')->setLabel('Provider')->setHtml(false))
            ->addField(EntityListField::make('severity')->setLabel('Severity')->setHtml(false))
            ->addField(EntityListField::make('trigger')->setLabel('Trigger')->setHtml(false))
            ->addField(EntityListStateField::make()->setLabel('Status'));
    }

    public function buildListConfig(): void
    {
        $this
            ->configureDefaultSort('created_at', 'desc')
            ->configureEntityState('status', AgentRunStatusState::class);
    }

    public function getListData(): array|Arrayable
    {
        return $this
            ->setCustomTransformer('severity', fn ($value) => $value ?: '—')
            ->setCustomTransformer('created_at', fn ($value) => $value ? Carbon::parse($value)->format('Y-m-d H:i') : null)
            ->transform(
                AgentRun::with('machine')
                    ->orderBy($this->queryParams->sortedBy() ?: 'created_at', $this->queryParams->sortedDir() ?: 'desc')
                    ->paginate(30)
            );
    }
}
