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
            ->addField(SharpShowTextField::make('machine:name')->setLabel('Machine')->setHtml(false))
            ->addField(SharpShowTextField::make('objective')->setLabel('Objective')->setHtml(false))
            ->addField(SharpShowTextField::make('severity')->setLabel('Verdict')->setHtml(false))
            ->addField(SharpShowTextField::make('summary')->setLabel('Summary')->setHtml(false))
            ->addField(SharpShowTextField::make('report')->setLabel('Report')->setHtml(false));
    }

    public function buildShowConfig(): void
    {
        $this->configureEntityState('status', AgentRunStatusState::class);
    }

    protected function buildShowLayout(ShowLayout $showLayout): void
    {
        $showLayout
            ->addSection('Scan', fn (ShowLayoutSection $section) => $section
                ->addColumn(6, fn (ShowLayoutColumn $column) => $column->withField('machine:name')->withField('severity')->withField('summary'))
                ->addColumn(6, fn (ShowLayoutColumn $column) => $column->withField('objective')))
            ->addSection('Report', fn (ShowLayoutSection $section) => $section
                ->addColumn(12, fn (ShowLayoutColumn $column) => $column->withField('report')));
    }

    protected function find(mixed $id): array
    {
        return $this
            ->setCustomTransformer('severity', fn ($value) => $value ?: 'no verdict')
            ->setCustomTransformer('summary', fn ($value) => $value ?: '—')
            ->setCustomTransformer('report', fn ($value) => $value ?: '(no report yet)')
            ->transform(AgentRun::with('machine')->findOrFail($id));
    }

    public function delete(mixed $id): void
    {
        AgentRun::findOrFail($id)->delete();
    }
}
