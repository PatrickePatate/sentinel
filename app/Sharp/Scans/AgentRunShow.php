<?php

namespace App\Sharp\Scans;

use App\Models\AgentRun;
use Code16\Sharp\Show\Fields\SharpShowTextField;
use Code16\Sharp\Show\Layout\ShowLayout;
use Code16\Sharp\Show\Layout\ShowLayoutColumn;
use Code16\Sharp\Show\Layout\ShowLayoutSection;
use Code16\Sharp\Show\SharpShow;
use Code16\Sharp\Utils\Fields\FieldsContainer;

class AgentRunShow extends SharpShow
{
    protected function buildShowFields(FieldsContainer $showFields): void
    {
        $showFields
            ->addField(SharpShowTextField::make('machine:name')->setLabel('Machine'))
            ->addField(SharpShowTextField::make('objective')->setLabel('Objective'))
            ->addField(SharpShowTextField::make('status')->setLabel('Status'))
            ->addField(SharpShowTextField::make('report')->setLabel('Report'));
    }

    protected function buildShowLayout(ShowLayout $showLayout): void
    {
        $showLayout
            ->addSection('Scan', fn (ShowLayoutSection $section) => $section
                ->addColumn(6, fn (ShowLayoutColumn $column) => $column->withField('machine:name')->withField('status'))
                ->addColumn(6, fn (ShowLayoutColumn $column) => $column->withField('objective')))
            ->addSection('Report', fn (ShowLayoutSection $section) => $section
                ->addColumn(12, fn (ShowLayoutColumn $column) => $column->withField('report')));
    }

    protected function find(mixed $id): array
    {
        return $this
            ->setCustomTransformer('report', fn ($value) => $value ?: '(no report yet)')
            ->transform(AgentRun::with('machine')->findOrFail($id));
    }

    public function delete(mixed $id): void
    {
        AgentRun::findOrFail($id)->delete();
    }
}
