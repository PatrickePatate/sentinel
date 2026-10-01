<div @if ($live) @realtime wire:poll.20s @else wire:poll.2s @endrealtime @else @realtime wire:poll.60s @else wire:poll.15s @endrealtime @endif class="space-y-6">
    <x-ui.page-header title="Scans" description="Audits run by the agent, on demand or on a schedule.">
        <x-slot:actions><x-ui.live :active="$live" :label="$live ? 'Scan in progress' : 'Live'" /></x-slot:actions>
    </x-ui.page-header>
    <div class="flex flex-wrap gap-2">
        <x-ui.select wire:model.live="status" class="w-44" :options="['' => 'All statuses', 'queued' => 'Queued', 'running' => 'Running', 'completed' => 'Completed', 'failed' => 'Failed']" />
        <x-ui.select wire:model.live="machine" class="w-52" :options="['' => 'All machines'] + $machines->all()" />
    </div>
    <x-ui.card flush>
        @if ($runs->isEmpty())
            <x-ui.empty icon="lucide-scan-search" title="No scans" description="Start one from a machine page." />
        @else
            <x-ui.table>
                <thead><tr><th>Date</th><th>Machine</th><th>Objective</th><th>Verdict</th><th>Trigger</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach ($runs as $run)
                        <tr wire:key="r{{ $run->id }}" class="cursor-pointer" onclick="Livewire.navigate('{{ route('scans.show', $run) }}')">
                            <td class="whitespace-nowrap text-muted-foreground">{{ $run->created_at->format('M d H:i') }}</td>
                            <td class="font-medium">{{ $run->machine->name }}</td>
                            <td class="max-w-sm truncate">{{ $run->summary ?: $run->objective }}</td>
                            <td>@if ($run->severity)<x-ui.status-badge :status="$run->severity" />@else—@endif</td>
                            <td class="text-muted-foreground">{{ $run->profileLabel() ? $run->profileLabel().' · ' : '' }}{{ $run->trigger }}</td>
                            <td><x-ui.status-badge :status="$run->status" /></td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>
    {{ $runs->links() }}
</div>
