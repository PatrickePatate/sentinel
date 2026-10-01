@if ($entries->isEmpty())
    <x-ui.empty icon="lucide-activity" title="No activity yet" />
@else
    <x-ui.table>
        <thead><tr><th>When</th><th>Machine</th><th>Event</th><th>Tool / action</th><th>Command / reason</th></tr></thead>
        <tbody>
            @foreach ($entries as $entry)
                <tr wire:key="a{{ $entry->id }}">
                    <td class="whitespace-nowrap text-muted-foreground">{{ $entry->created_at->format('M d H:i:s') }}</td>
                    <td>{{ $entry->subject?->name ?? '—' }}</td>
                    <td><x-ui.badge :variant="match ($entry->event) { 'ok', 'host_key_pinned', 'client_updated', 'action_executed' => 'success', 'failed', 'rejected', 'action_refused', 'machine_revoked', 'client_update_failed' => 'destructive', 'action_proposed', 'action_pending' => 'warning', default => 'secondary' }">{{ $entry->event }}</x-ui.badge></td>
                    <td class="font-medium">{{ $entry->description }}</td>
                    <td class="max-w-md truncate font-mono text-xs text-muted-foreground" title="{{ $entry->properties['command'] ?? $entry->properties['reason'] ?? $entry->properties['fingerprint'] ?? '' }}">{{ $entry->properties['command'] ?? $entry->properties['reason'] ?? $entry->properties['fingerprint'] ?? '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </x-ui.table>
@endif
