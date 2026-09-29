<?php

namespace App\Sharp\AuditLog;

use Code16\Sharp\EntityList\Fields\EntityListField;
use Code16\Sharp\EntityList\Fields\EntityListFieldsContainer;
use Code16\Sharp\EntityList\SharpEntityList;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

class AuditEntryList extends SharpEntityList
{
    protected function buildList(EntityListFieldsContainer $fields): void
    {
        $fields
            ->addField(EntityListField::make('created_at')->setLabel('Date')->setHtml(false))
            ->addField(EntityListField::make('machine')->setLabel('Machine')->setHtml(false))
            ->addField(EntityListField::make('event')->setLabel('Event')->setHtml(false))
            ->addField(EntityListField::make('description')->setLabel('Tool / action')->setHtml(false))
            ->addField(EntityListField::make('detail')->setLabel('Command / reason')->setHtml(false));
    }

    public function buildListConfig(): void
    {
        $this->configureSearchable();
    }

    public function getListData(): array|Arrayable
    {
        $entries = Activity::query()
            ->where('log_name', 'ssh')
            ->with('subject')
            ->when($this->queryParams->hasSearch(), function ($query) {
                foreach ($this->queryParams->searchWords() as $word) {
                    $query->where(fn ($q) => $q->where('description', 'like', $word)->orWhere('event', 'like', $word));
                }
            })
            ->latest('id');

        return $this
            ->setCustomTransformer('created_at', fn ($value) => $value ? Carbon::parse($value)->format('Y-m-d H:i:s') : null)
            ->setCustomTransformer('machine', fn ($value, Activity $a) => $a->subject?->name)
            ->setCustomTransformer('detail', fn ($value, Activity $a) => $a->properties['command'] ?? $a->properties['reason'] ?? $a->properties['fingerprint'] ?? '')
            ->transform($entries->paginate(50));
    }
}
