<?php

namespace App\Sharp\Notifications;

use App\Models\NotificationChannel;
use Code16\Sharp\EntityList\Fields\EntityListField;
use Code16\Sharp\EntityList\Fields\EntityListFieldsContainer;
use Code16\Sharp\EntityList\SharpEntityList;
use Illuminate\Contracts\Support\Arrayable;

class NotificationChannelList extends SharpEntityList
{
    protected function buildList(EntityListFieldsContainer $fields): void
    {
        $fields
            ->addField(EntityListField::make('name')->setLabel('Name')->setHtml(false))
            ->addField(EntityListField::make('type')->setLabel('Type')->setHtml(false))
            ->addField(EntityListField::make('target')->setLabel('Target')->setHtml(false))
            ->addField(EntityListField::make('events')->setLabel('Notifies')->setHtml(false))
            ->addField(EntityListField::make('enabled')->setLabel('Status')->setHtml(false));
    }

    public function buildListConfig(): void
    {
        $this->configureDelete(confirmationText: 'Delete this channel?');
    }

    public function getInstanceCommands(): ?array
    {
        return [SendTestNotificationCommand::class, RegisterTelegramWebhookCommand::class];
    }

    public function getListData(): array|Arrayable
    {
        return $this
            ->setCustomTransformer('type', fn ($value) => NotificationChannel::TYPES[$value] ?? $value)
            ->setCustomTransformer('target', fn ($value, NotificationChannel $c) => $c->type === 'mail' ? $c->setting('email') : 'chat '.$c->setting('chat_id'))
            ->setCustomTransformer('events', fn ($value, NotificationChannel $c) => collect([
                $c->notify_scans ? "scans ≥ {$c->min_severity}" : null,
                $c->notify_approvals ? 'approvals' : null,
            ])->filter()->implode(', ') ?: 'nothing')
            ->setCustomTransformer('enabled', fn ($value) => $value ? 'enabled' : 'disabled')
            ->transform(NotificationChannel::orderBy('name')->paginate(30));
    }
}
