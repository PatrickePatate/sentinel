<?php

namespace App\Sharp\PendingActions;

use App\Models\PendingAction;
use Code16\Sharp\Show\Fields\SharpShowTextField;
use Code16\Sharp\Show\Layout\ShowLayout;
use Code16\Sharp\Show\Layout\ShowLayoutColumn;
use Code16\Sharp\Show\Layout\ShowLayoutSection;
use Code16\Sharp\Show\SharpShow;
use Code16\Sharp\Utils\Fields\FieldsContainer;

class PendingActionShow extends SharpShow
{
    protected function buildShowFields(FieldsContainer $showFields): void
    {
        $showFields
            ->addField(SharpShowTextField::make('machine:name')->setLabel('Machine')->setHtml(false))
            ->addField(SharpShowTextField::make('command')->setLabel('Exact command')->setHtml(false))
            ->addField(SharpShowTextField::make('risk')->setLabel('Risk')->setHtml(false))
            ->addField(SharpShowTextField::make('reason')->setLabel('Why it was held')->setHtml(false))
            ->addField(SharpShowTextField::make('output')->setLabel('Output')->setHtml(false));
    }

    public function buildShowConfig(): void
    {
        $this->configureEntityState('status', PendingActionStatusState::class);
    }

    protected function buildShowLayout(ShowLayout $showLayout): void
    {
        $showLayout->addSection('Action', fn (ShowLayoutSection $section) => $section
            ->addColumn(6, fn (ShowLayoutColumn $column) => $column
                ->withField('machine:name')->withField('command')->withField('risk'))
            ->addColumn(6, fn (ShowLayoutColumn $column) => $column
                ->withField('reason')->withField('output')));
    }

    public function getInstanceCommands(): ?array
    {
        return [ApprovePendingActionCommand::class, RejectPendingActionCommand::class];
    }

    protected function find(mixed $id): array
    {
        return $this->transform(PendingAction::with('machine')->findOrFail($id));
    }

    public function delete(mixed $id): void
    {
        abort(403);
    }
}
