<div @realtime wire:poll.60s @else wire:poll.10s @endrealtime class="space-y-6">
    <x-ui.page-header title="Machines" description="Servers Sentinel watches over SSH.">
        <x-slot:actions>
            @can('admin')
            @if ($outdated)
                <x-ui.button variant="outline" wire:click="updateOutdated" wire:loading.attr="disabled" wire:target="updateOutdated">@svg('lucide-cloud-download') Update {{ $outdated }} client{{ $outdated > 1 ? 's' : '' }}</x-ui.button>
            @endif
            <x-ui.button :href="route('machines.create')">@svg('lucide-plus') Add machine</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.input type="search" wire:model.live.debounce.300ms="search" placeholder="Search by name or address…" class="max-w-xs" />

    <x-ui.card flush>
        @if ($machines->isEmpty())
            <x-ui.empty icon="lucide-server" title="{{ $search ? 'No machine matches' : 'No machines yet' }}" description="Add a machine, then provision it with a single command." />
        @else
            <x-ui.table>
                <thead><tr><th>Name</th><th>Address</th><th>Host key</th><th>Latest verdict</th><th>Scans</th><th>Client</th><th></th></tr></thead>
                <tbody>
                    @foreach ($machines as $machine)
                        <tr wire:key="m{{ $machine->id }}">
                            <td>
                                <a href="{{ route('machines.show', $machine) }}" wire:navigate class="font-medium hover:underline">{{ $machine->name }}</a>
                                <x-ui.badge :variant="$machine->environment === 'production' ? 'outline' : 'secondary'" class="ml-1">{{ $machine->environment }}</x-ui.badge>
                                @if ($machine->isRevoked())<x-ui.badge variant="destructive">Revoked</x-ui.badge>@endif
                            </td>
                            <td class="font-mono text-xs text-muted-foreground">{{ $machine->username.'@'.$machine->host.':'.$machine->port }}</td>
                            <td>@if ($machine->host_key_fingerprint)<x-ui.badge variant="success">Pinned</x-ui.badge>@else<x-ui.badge variant="warning">Not pinned</x-ui.badge>@endif</td>
                            <td>@if ($v = $severities->get($machine->id))<a href="{{ route('scans.show', $v) }}" wire:navigate><x-ui.status-badge :status="$v->severity" /></a>@else<span class="text-muted-foreground">—</span>@endif</td>
                            <td class="text-muted-foreground">
                                @if ($active->has($machine->id))<x-ui.live label="Scanning" />
                                @else{{ $machine->scan_interval_minutes ? (config('sentinel.scheduling.frequencies')[$machine->scan_interval_minutes] ?? 'custom') : 'Manual' }}@endif
                            </td>
                            <td>@if (! $machine->client_checked_at)<span class="text-muted-foreground">Not checked</span>@else<x-ui.status-badge :status="$clients[$machine->id]->state" />@endif</td>
                            <td class="text-right">
                                <x-ui.button size="sm" variant="outline" wire:click="startScan({{ $machine->id }})" :disabled="$machine->isRevoked() || ! $machine->host_key_fingerprint">@svg('lucide-scan-search') Scan</x-ui.button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>

    @include('livewire.partials.scan-modal')
</div>
