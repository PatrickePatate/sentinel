<div @realtime wire:poll.30s @else wire:poll.5s @endrealtime class="space-y-6">
    <x-ui.page-header title="Dashboard" description="What your machines and the agent are doing right now.">
        <x-slot:actions>
            <x-ui.live />
            <x-ui.button href="{{ route('machines.create') }}">@svg('lucide-plus') Add machine</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat label="Machines" :value="$machines->count()" icon="lucide-server" :hint="$unpinned ? $unpinned.' without a pinned host key' : 'All host keys pinned'" :href="route('machines.index')" />
        <x-ui.stat label="Needs attention" :value="$attention" icon="lucide-triangle-alert" :tone="$attention ? 'danger' : null" hint="High or critical in the latest scan" :href="route('scans.index')" />
        <x-ui.stat label="Scans running" :value="$activeRuns->count()" icon="lucide-scan-search" :tone="$activeRuns->count() ? 'warning' : null" hint="Queued or in progress" :href="route('scans.index', ['status' => 'running'])" />
        <x-ui.stat label="Awaiting approval" :value="$pendingCount" icon="lucide-hand" :tone="$pendingCount ? 'warning' : null" hint="Corrective actions held for you" :href="route('actions.index')" />
    </div>

    @if ($activeRuns->isNotEmpty())
        <x-ui.card title="In progress" flush>
            <div class="divide-y">
                @foreach ($activeRuns as $run)
                    <a href="{{ route('scans.show', $run->parent_run_id ?? $run) }}" wire:navigate class="flex items-center gap-3 px-6 py-3 hover:bg-muted/40">
                        <x-ui.status-badge :status="$run->status" />
                        <span class="font-medium">{{ $run->machine->name }}</span>
                        <span class="min-w-0 flex-1 truncate text-sm text-muted-foreground">{{ $run->progress ?: $run->objective }}</span>
                    </a>
                @endforeach
            </div>
        </x-ui.card>
    @endif

    <div class="grid gap-6 xl:grid-cols-3">
        <x-ui.card title="Fleet" description="Latest verdict per machine" flush class="xl:col-span-2">
            @if ($machines->isEmpty())
                <x-ui.empty icon="lucide-server" title="No machines yet" description="Add a machine, then provision it with one command.">
                    <x-ui.button :href="route('machines.create')" size="sm" class="mt-2">Add machine</x-ui.button>
                </x-ui.empty>
            @else
                <x-ui.table>
                    <thead><tr><th>Machine</th><th>Environment</th><th>Latest verdict</th><th>Last scan</th></tr></thead>
                    <tbody>
                        @foreach ($machines as $machine)
                            @php($verdict = $latest->get($machine->id))
                            <tr>
                                <td><a href="{{ route('machines.show', $machine) }}" wire:navigate class="font-medium hover:underline">{{ $machine->name }}</a>
                                    @if ($machine->isRevoked())<x-ui.badge variant="destructive" class="ml-1">Revoked</x-ui.badge>@elseif (! $machine->host_key_fingerprint)<x-ui.badge variant="warning" class="ml-1">Not pinned</x-ui.badge>@endif</td>
                                <td><x-ui.badge :variant="$machine->environment === 'production' ? 'outline' : 'secondary'">{{ $machine->environment }}</x-ui.badge></td>
                                <td>@if ($verdict)<a href="{{ route('scans.show', $verdict) }}" wire:navigate><x-ui.status-badge :status="$verdict->severity" /></a>@else<span class="text-muted-foreground">—</span>@endif</td>
                                <td class="text-muted-foreground">{{ $verdict?->created_at->diffForHumans() ?? 'never' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </x-ui.table>
            @endif
        </x-ui.card>

        <x-ui.card title="Waiting for you" flush>
            @forelse ($pending as $action)
                <a href="{{ route('actions.index') }}" wire:navigate class="block border-b px-6 py-3 last:border-0 hover:bg-muted/40">
                    <div class="flex items-center justify-between gap-2"><span class="text-sm font-medium">{{ $action->machine->name }}</span><x-ui.status-badge :status="$action->risk" /></div>
                    <code class="mt-1 block truncate text-xs text-muted-foreground">{{ $action->command }}</code>
                </a>
            @empty
                <x-ui.empty icon="lucide-check" title="Nothing to approve" />
            @endforelse
        </x-ui.card>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <x-ui.card title="Recent scans" flush>
            @forelse ($recentRuns as $run)
                <a href="{{ route('scans.show', $run) }}" wire:navigate class="flex items-center gap-3 border-b px-6 py-3 last:border-0 hover:bg-muted/40">
                    <x-ui.status-badge :status="$run->status" />
                    <div class="min-w-0 flex-1"><p class="truncate text-sm font-medium">{{ $run->machine->name }}</p><p class="truncate text-xs text-muted-foreground">{{ $run->summary ?: $run->objective }}</p></div>
                    @if ($run->severity)<x-ui.status-badge :status="$run->severity" />@endif
                    <span class="text-xs text-muted-foreground">{{ $run->created_at->diffForHumans(short: true) }}</span>
                </a>
            @empty
                <x-ui.empty icon="lucide-scan-search" title="No scans yet" />
            @endforelse
        </x-ui.card>

        <x-ui.card title="Live activity" description="Every command run on your machines" flush>
            @forelse ($activity as $entry)
                <div class="flex items-center gap-3 border-b px-6 py-2.5 text-sm last:border-0">
                    <x-ui.badge :variant="match ($entry->event) { 'ok', 'host_key_pinned', 'client_updated', 'action_executed' => 'success', 'failed', 'rejected', 'action_refused', 'machine_revoked', 'client_update_failed' => 'destructive', 'action_proposed', 'action_pending' => 'warning', default => 'secondary' }">{{ $entry->event }}</x-ui.badge>
                    <span class="min-w-0 flex-1 truncate"><span class="font-medium">{{ $entry->description }}</span> <span class="text-muted-foreground">on {{ $entry->subject?->name ?? '—' }}</span></span>
                    <span class="text-xs text-muted-foreground">{{ $entry->created_at->diffForHumans(short: true) }}</span>
                </div>
            @empty
                <x-ui.empty icon="lucide-activity" title="No activity yet" />
            @endforelse
        </x-ui.card>
    </div>
</div>
