<?php

namespace App\Sharp\Machines;

use App\Models\Machine;
use Code16\Sharp\Show\Fields\SharpShowTextField;
use Code16\Sharp\Show\Layout\ShowLayout;
use Code16\Sharp\Show\Layout\ShowLayoutColumn;
use Code16\Sharp\Show\Layout\ShowLayoutSection;
use Code16\Sharp\Show\SharpShow;
use Code16\Sharp\Utils\Fields\FieldsContainer;

class MachineShow extends SharpShow
{
    protected function buildShowFields(FieldsContainer $showFields): void
    {
        $showFields
            ->addField(SharpShowTextField::make('name')->setLabel('Name'))
            ->addField(SharpShowTextField::make('address')->setLabel('Address'))
            ->addField(SharpShowTextField::make('environment')->setLabel('Environment'))
            ->addField(SharpShowTextField::make('host_key_fingerprint')->setLabel('Pinned host key'))
            ->addField(SharpShowTextField::make('autonomy')->setLabel('Autonomous low-risk actions'))
            ->addField(SharpShowTextField::make('chat')->setLabel(''));
    }

    protected function buildShowLayout(ShowLayout $showLayout): void
    {
        $showLayout->addSection('Machine', fn (ShowLayoutSection $section) => $section
            ->addColumn(6, fn (ShowLayoutColumn $column) => $column
                ->withField('name')->withField('address')->withField('environment'))
            ->addColumn(6, fn (ShowLayoutColumn $column) => $column
                ->withField('host_key_fingerprint')->withField('autonomy'))
        )->addSection('Chat with the agent', fn (ShowLayoutSection $section) => $section
            ->addColumn(12, fn (ShowLayoutColumn $column) => $column->withField('chat'))
        );
    }

    public function buildShowConfig(): void
    {
        $this->configurePageTitleAttribute('name');
    }

    public function getInstanceCommands(): ?array
    {
        return [ScanMachineCommand::class, FetchHostKeyCommand::class, PinHostKeyCommand::class];
    }

    protected function find(mixed $id): array
    {
        return $this
            ->setCustomTransformer('address', fn ($value, Machine $m) => "{$m->username}@{$m->host}:{$m->port}")
            ->setCustomTransformer('host_key_fingerprint', fn ($value) => $value ?: 'NOT PINNED — the agent cannot connect')
            ->setCustomTransformer('chat', fn ($value, Machine $m) => '<iframe src="'.route('sentinel.chat', $m, absolute: false).'" title="Chat with the agent" style="width:100%;height:640px;border:1px solid #e5e7eb;border-radius:8px" loading="lazy"></iframe>')
            ->setCustomTransformer('autonomy', fn ($value, Machine $m) => $m->autonomy_enabled ? 'enabled' : 'off')
            ->transform(Machine::findOrFail($id));
    }

    public function delete(mixed $id): void
    {
        Machine::findOrFail($id)->delete();
    }
}
