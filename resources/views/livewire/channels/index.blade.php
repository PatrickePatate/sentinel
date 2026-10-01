<div class="space-y-6">
    <x-ui.page-header title="Notifications" description="Where humans get told about suspicious scans and actions waiting for approval.">
        <x-slot:actions><x-ui.button :href="route('channels.create')">@svg('lucide-plus') Add channel</x-ui.button></x-slot:actions>
    </x-ui.page-header>
    <x-ui.card flush>
        @if ($channels->isEmpty())
            <x-ui.empty icon="lucide-bell" title="No channel yet" description="Add an email address or a Telegram chat to be notified, and to approve actions from your phone." />
        @else
            <x-ui.table>
                <thead><tr><th>Name</th><th>Type</th><th>Target</th><th>Notifies</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @foreach ($channels as $channel)
                        <tr wire:key="c{{ $channel->id }}">
                            <td class="font-medium">{{ $channel->name }}</td>
                            <td>{{ \App\Models\NotificationChannel::TYPES[$channel->type] ?? $channel->type }}</td>
                            <td class="text-muted-foreground">{{ $channel->type === 'mail' ? $channel->setting('email') : 'chat '.$channel->setting('chat_id') }}</td>
                            <td class="text-muted-foreground">{{ collect([$channel->notify_scans ? "scans ≥ {$channel->min_severity}" : null, $channel->notify_approvals ? 'approvals' : null])->filter()->implode(', ') ?: 'nothing' }}</td>
                            <td><x-ui.badge :variant="$channel->enabled ? 'success' : 'secondary'">{{ $channel->enabled ? 'Enabled' : 'Disabled' }}</x-ui.badge></td>
                            <td class="text-right">
                                <x-ui.dropdown>
                                    <x-slot:trigger><x-ui.button variant="ghost" size="icon" aria-label="Actions">@svg('lucide-ellipsis')</x-ui.button></x-slot:trigger>
                                    <x-ui.dropdown-item wire:click="test({{ $channel->id }})">@svg('lucide-send') Send a test</x-ui.dropdown-item>
                                    @if ($channel->type === 'telegram')<x-ui.dropdown-item wire:click="registerWebhook({{ $channel->id }})">@svg('lucide-webhook') Register webhook</x-ui.dropdown-item>@endif
                                    <x-ui.dropdown-item :href="route('channels.edit', $channel)">@svg('lucide-pencil') Edit</x-ui.dropdown-item>
                                    <x-ui.dropdown-item destructive x-on:click="$store.confirm.ask({ title: 'Delete this channel?', label: 'Delete', destructive: true, action: () => $wire.delete({{ $channel->id }}) })">@svg('lucide-trash-2') Delete</x-ui.dropdown-item>
                                </x-ui.dropdown>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>
</div>
