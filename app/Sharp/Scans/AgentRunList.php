<?php

namespace App\Sharp\Scans;

use App\Models\AgentRun;
use Code16\Sharp\EntityList\Fields\EntityListField;
use Code16\Sharp\EntityList\Fields\EntityListFieldsContainer;
use Code16\Sharp\EntityList\SharpEntityList;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;

class AgentRunList extends SharpEntityList
{
    protected function buildList(EntityListFieldsContainer $fields): void
    {
        $fields
            ->addField(EntityListField::make('created_at')->setLabel('Date')->setSortable())
            ->addField(EntityListField::make('machine:name')->setLabel('Machine'))
            ->addField(EntityListField::make('objective')->setLabel('Objective'))
            ->addField(EntityListField::make('provider')->setLabel('Provider'))
            ->addField(EntityListField::make('status')->setLabel('Status'));
    }

    public function buildListConfig(): void
    {
        $this->configureDefaultSort('created_at', 'desc');
    }

    public function getListData(): array|Arrayable
    {
        return $this
            ->setCustomTransformer('created_at', fn ($value) => $value ? Carbon::parse($value)->format('Y-m-d H:i') : null)
            ->transform(
                AgentRun::with('machine')
                    ->orderBy($this->queryParams->sortedBy() ?: 'created_at', $this->queryParams->sortedDir() ?: 'desc')
                    ->paginate(30)
            );
    }
}
