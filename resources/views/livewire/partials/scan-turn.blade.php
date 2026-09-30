<div class="row assistant" style="max-width:100%">
    <div class="avatar">@svg($icon)</div>
    <div class="bubble md" style="max-width:100%;flex:1">
        <div class="scan-meta">
            <span class="badge {{ $turn->status === 'failed' ? 'prod' : '' }}">{{ $turn->status }}</span>
            @if ($turn->severity)
                <span class="badge {{ in_array($turn->severity, ['high', 'critical']) ? 'prod' : '' }}">severity: {{ $turn->severity }}</span>
            @endif
            @if ($turn->pendingActions->isNotEmpty())
                <span class="badge">{{ $turn->pendingActions->where('status', 'pending')->count() }} awaiting approval / {{ $turn->pendingActions->count() }} actions</span>
            @endif
            @if ($turn->progress)
                <span class="hint">{{ $turn->progress }}</span>
            @endif
        </div>

        @if ($turn->summary)
            <p><strong>{{ $turn->summary }}</strong></p>
        @endif

        @if (filled($turn->report))
            {!! \App\Support\SafeMarkdown::render($turn->report) !!}
        @elseif ($turn->isActive())
            <span class="typing"><i></i><i></i><i></i></span>
            <div class="hint">{{ $turn->status === 'queued' ? 'Waiting for a worker to pick it up…' : 'The agent is investigating…' }}</div>
        @else
            <em class="hint">(no report)</em>
        @endif
    </div>
</div>
