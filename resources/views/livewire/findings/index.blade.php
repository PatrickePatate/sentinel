<div @realtime wire:poll.60s @else wire:poll.30s @endrealtime class="space-y-6">
    <x-ui.page-header title="Issues" description="Problems the scans found, followed from one scan to the next until a scan no longer sees them." />

    <div class="grid gap-4 sm:grid-cols-4">
        @foreach (['critical', 'high', 'medium', 'low'] as $level)
            <button type="button" wire:click="$set('severity', '{{ $severity === $level ? '' : $level }}')" @class(['rounded-lg border bg-card p-4 text-left transition-colors hover:bg-accent/60', 'ring-2 ring-ring' => $severity === $level])>
                <p class="text-xs capitalize text-muted-foreground">{{ $level }}</p>
                <p class="text-2xl font-semibold">{{ $counts[$level] ?? 0 }}</p>
            </button>
        @endforeach
    </div>

    <div class="flex flex-wrap gap-2">
        <x-ui.select wire:model.live="status" class="w-44" :options="['unresolved' => 'Not resolved', 'open' => 'Open', 'acknowledged' => 'Acknowledged', 'muted' => 'Muted', 'resolved' => 'Resolved', 'all' => 'All']" />
        <x-ui.select wire:model.live="machine" class="w-52" :options="['' => 'All machines'] + $machines->all()" />
    </div>

    <x-ui.card flush>
        @if ($findings->isEmpty())
            <x-ui.empty icon="lucide-shield-check" title="No issue" description="Scans list each problem they find here. Nothing matches these filters." />
        @else
            <x-ui.table>
                <thead><tr><th>Issue</th><th>Machine</th><th>Severity</th><th>Seen</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @foreach ($findings as $finding)
                        <tr wire:key="f{{ $finding->id }}">
                            <td class="max-w-md">
                                <span class="block font-medium">{{ $finding->title }}</span>
                                <span class="block truncate font-mono text-xs text-muted-foreground" title="{{ $finding->evidence }}">{{ $finding->key }}@if ($finding->evidence) · {{ $finding->evidence }}@endif</span>
                            </td>
                            <td><a href="{{ route('machines.show', $finding->machine) }}" wire:navigate class="hover:underline">{{ $finding->machine->name }}</a></td>
                            <td><x-ui.status-badge :status="$finding->severity" /></td>
                            <td class="whitespace-nowrap text-xs text-muted-foreground">
                                since {{ $finding->first_seen_at?->diffForHumans() ?? '—' }}<br>
                                @if ($finding->last_seen_run_id)<a class="underline" href="{{ route('scans.show', $finding->last_seen_run_id) }}" wire:navigate>last {{ $finding->last_seen_at?->diffForHumans() }}</a> · {{ $finding->occurrences }}×@endif
                            </td>
                            <td>
                                @if ($finding->status === 'muted')
                                    <x-ui.badge>Muted until {{ $finding->muted_until?->format('M d') }}</x-ui.badge>
                                @elseif ($finding->status === 'resolved')
                                    <x-ui.badge variant="success">Resolved</x-ui.badge> <span class="text-xs text-muted-foreground">{{ $finding->resolved_at?->diffForHumans() }}</span>
                                @elseif ($finding->status === 'acknowledged')
                                    <x-ui.badge variant="info">Acknowledged</x-ui.badge>
                                @else
                                    <x-ui.badge variant="warning" dot>Open</x-ui.badge>
                                @endif
                            </td>
                            <td class="text-right">
                                <x-ui.dropdown>
                                    <x-slot:trigger><x-ui.button variant="ghost" size="icon" aria-label="Change">@svg('lucide-ellipsis')</x-ui.button></x-slot:trigger>
                                    @if ($finding->status === 'open')
                                        <x-ui.dropdown-item wire:click="acknowledge({{ $finding->id }})">@svg('lucide-eye') Acknowledge</x-ui.dropdown-item>
                                    @endif
                                    @if ($finding->status !== 'resolved')
                                        @foreach (\App\Livewire\Findings\Index::MUTE_DAYS as $days)
                                            <x-ui.dropdown-item wire:click="mute({{ $finding->id }}, {{ $days }})">@svg('lucide-bell-off') Mute {{ $days }} days</x-ui.dropdown-item>
                                        @endforeach
                                        <x-ui.dropdown-item wire:click="resolve({{ $finding->id }})">@svg('lucide-check') Mark as fixed</x-ui.dropdown-item>
                                    @endif
                                    @if ($finding->status !== 'open')
                                        <x-ui.dropdown-item wire:click="reopen({{ $finding->id }})">@svg('lucide-rotate-ccw') Reopen</x-ui.dropdown-item>
                                    @endif
                                </x-ui.dropdown>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>
    {{ $findings->links() }}
</div>
