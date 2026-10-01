<x-ui.card>
    <div class="mb-4 flex flex-wrap items-center gap-2">
        <x-ui.status-badge :status="$turn->status" />
        @if ($turn->severity)<x-ui.status-badge :status="$turn->severity" />@endif
        @if ($turn->progress)<span class="text-xs text-muted-foreground">{{ $turn->progress }}</span>@endif
        <span class="ml-auto text-xs text-muted-foreground">{{ $isScan ? $turn->objective : '' }}</span>
    </div>
    @if ($turn->summary)<p class="mb-3 font-semibold">{{ $turn->summary }}</p>@endif
    @if (filled($turn->report))
        <div class="md">{!! \App\Support\SafeMarkdown::render($turn->report) !!}</div>
    @elseif ($turn->isActive())
        <span class="typing"><i></i><i></i><i></i></span>
        <p class="text-xs text-muted-foreground">{{ $turn->status === 'queued' ? 'Waiting for a queue worker to pick it up…' : 'The agent is investigating…' }}</p>
    @else
        <p class="text-sm italic text-muted-foreground">(no report)</p>
    @endif
</x-ui.card>
