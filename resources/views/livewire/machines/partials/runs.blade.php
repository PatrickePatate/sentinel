@forelse ($runs as $run)
    <a href="{{ route('scans.show', $run) }}" wire:navigate class="flex items-center gap-3 border-b px-6 py-3 last:border-0 hover:bg-muted/40">
        <x-ui.status-badge :status="$run->status" />
        <div class="min-w-0 flex-1"><p class="truncate text-sm font-medium">{{ $run->summary ?: $run->objective }}</p><p class="text-xs text-muted-foreground">{{ $run->profileLabel() ? $run->profileLabel().' · ' : '' }}{{ $run->trigger }} · {{ $run->created_at->diffForHumans() }}</p></div>
        @if ($run->severity)<x-ui.status-badge :status="$run->severity" />@endif
    </a>
@empty
    <x-ui.empty icon="lucide-scan-search" title="No scans yet" description="Run a scan to get the agent's first report." />
@endforelse
