<?php

namespace App\Sharp\Notifications;

use App\Ai\Severity;
use App\Models\NotificationChannel;
use Code16\Sharp\Form\Fields\SharpFormCheckField;
use Code16\Sharp\Form\Fields\SharpFormSelectField;
use Code16\Sharp\Form\Fields\SharpFormTextField;
use Code16\Sharp\Form\Layout\FormLayout;
use Code16\Sharp\Form\Layout\FormLayoutColumn;
use Code16\Sharp\Form\SharpForm;
use Code16\Sharp\Utils\Fields\FieldsContainer;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class NotificationChannelForm extends SharpForm
{
    public function buildFormFields(FieldsContainer $formFields): void
    {
        $formFields
            ->addField(SharpFormTextField::make('name')->setLabel('Name')->setMaxLength(100))
            ->addField(SharpFormSelectField::make('type', NotificationChannel::TYPES)->setLabel('Type')->setDisplayAsDropdown())
            ->addField(SharpFormTextField::make('email')->setLabel('Email address')->setMaxLength(255)->addConditionalDisplay('type', 'mail'))
            ->addField(SharpFormTextField::make('bot_token')->setLabel('Bot token')->setInputTypePassword()
                ->setHelpMessage('Write-only (from @BotFather). Leave empty to keep the current one.')->addConditionalDisplay('type', 'telegram'))
            ->addField(SharpFormTextField::make('chat_id')->setLabel('Chat id')->setMaxLength(32)->addConditionalDisplay('type', 'telegram'))
            ->addField(SharpFormTextField::make('approver_ids')->setLabel('Telegram user ids allowed to approve')->setMaxLength(255)
                ->setHelpMessage('Comma separated. Anyone else pressing a button is refused and audited. Empty = the chat itself (private chats only).')->addConditionalDisplay('type', 'telegram'))
            ->addField(SharpFormSelectField::make('min_severity', collect(Severity::cases())->mapWithKeys(fn (Severity $s) => [$s->value => ucfirst($s->value)])->all())
                ->setLabel('Notify about scans from severity')->setDisplayAsDropdown())
            ->addField(SharpFormCheckField::make('notify_scans', 'Scan results (suspicious findings, failed scans)'))
            ->addField(SharpFormCheckField::make('notify_approvals', 'Actions waiting for approval'))
            ->addField(SharpFormCheckField::make('enabled', 'Enabled'));
    }

    public function buildFormLayout(FormLayout $formLayout): void
    {
        $formLayout->addColumn(7, fn (FormLayoutColumn $column) => $column
            ->withField('name')->withField('type')->withField('email')
            ->withField('bot_token')->withField('chat_id')->withField('approver_ids')
            ->withField('min_severity')->withField('notify_scans')->withField('notify_approvals')->withField('enabled')
        );
    }

    public function find(mixed $id): array
    {
        $channel = NotificationChannel::findOrFail($id);

        return $this->transform($channel->setAttribute('email', $channel->setting('email'))
            ->setAttribute('chat_id', $channel->setting('chat_id'))
            ->setAttribute('approver_ids', $channel->setting('approver_ids')));
    }

    public function update(mixed $id, array $data)
    {
        $channel = $id ? NotificationChannel::findOrFail($id) : new NotificationChannel;

        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(array_keys(NotificationChannel::TYPES))],
            'email' => ['required_if:type,mail', 'nullable', 'email', 'max:255'],
            'bot_token' => ['nullable', 'string', 'max:255', 'regex:/^[0-9]+:[A-Za-z0-9_-]+$/'],
            'chat_id' => ['required_if:type,telegram', 'nullable', 'regex:/^-?[0-9]+$/'],
            'approver_ids' => ['nullable', 'regex:/^[0-9,\s]*$/'],
            'min_severity' => ['required', Rule::in(Severity::values())],
            'notify_scans' => ['boolean'],
            'notify_approvals' => ['boolean'],
            'enabled' => ['boolean'],
        ];

        $this->validate($data, $rules);
        $validated = Arr::only($data, array_keys($rules));

        $settings = $channel->settings ?? [];

        if ($validated['type'] === 'mail') {
            $settings = ['email' => $validated['email']];
        } else {
            if (blank($validated['bot_token'] ?? null) && blank($settings['bot_token'] ?? null)) {
                $this->validate($data, ['bot_token' => ['required']]);
            }

            $settings = [
                'bot_token' => $validated['bot_token'] ?: ($settings['bot_token'] ?? null),
                'chat_id' => $validated['chat_id'],
                'approver_ids' => $validated['approver_ids'] ?? '',
                'webhook_secret' => $settings['webhook_secret'] ?? null,
            ];
        }

        $channel->fill([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'min_severity' => $validated['min_severity'],
            'notify_scans' => $validated['notify_scans'] ?? false,
            'notify_approvals' => $validated['notify_approvals'] ?? false,
            'enabled' => $validated['enabled'] ?? false,
            'settings' => $settings,
        ])->save();

        return $channel->id;
    }

    public function delete(mixed $id): void
    {
        NotificationChannel::findOrFail($id)->delete();
    }

    public function create(): array
    {
        return ['type' => 'telegram', 'min_severity' => 'medium', 'notify_scans' => true, 'notify_approvals' => true, 'enabled' => true];
    }
}
