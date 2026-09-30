<?php

namespace App\Sharp\PendingActions;

use App\Models\AgentRun;
use App\Models\PendingAction;
use Code16\Sharp\EntityList\Fields\EntityListField;
use Code16\Sharp\EntityList\Fields\EntityListFieldsContainer;
use Code16\Sharp\EntityList\Fields\EntityListStateField;
use Code16\Sharp\EntityList\Filters\HiddenFilter;
use Code16\Sharp\EntityList\SharpEntityList;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;

class PendingActionList extends SharpEntityList
{
    protected function buildList(EntityListFieldsContainer $fields): void
    {
        $fields
            ->addField(EntityListField::make('created_at')->setLabel('Date')->setHtml(false))
            ->addField(EntityListField::make('machine:name')->setLabel('Machine')->setHtml(false))
            ->addField(EntityListField::make('command')->setLabel('Command')->setHtml(false))
            ->addField(EntityListField::make('risk')->setLabel('Risk')->setHtml(false))
            ->addField(EntityListField::make('reason')->setLabel('Why held')->setHtml(false))
            ->addField(EntityListStateField::make()->setLabel('Status'));
    }

    public function buildListConfig(): void
    {
        $this
            ->configureDefaultSort('created_at', 'desc')
            ->configureEntityState('status', PendingActionStatusState::class);
    }

    protected function getFilters(): ?array
    {
        // Set by the scan show page: the actions requested or proposed by that scan and its follow-ups.
        return [HiddenFilter::make('agent_run')];
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
                    ->when($this->queryParams->filterFor('agent_run'), fn ($query, $runId) => $query->whereIn(
                        'agent_run_id', AgentRun::whereKey($runId)->orWhere('parent_run_id', $runId)->select('id')
                    ))
                    ->orderByRaw("status = 'pending' desc")
                    ->latest()
                    ->paginate(30)
            );
    }
}
