<div class="chat" x-data
     x-init="const el = $refs.body; let follow = true; el.addEventListener('scroll', () => follow = el.scrollHeight - el.scrollTop - el.clientHeight < 60); new MutationObserver(() => follow && el.scrollTo({ top: el.scrollHeight })).observe(el, { childList: true, subtree: true, characterData: true })">
    {{-- Only poll while the scan is active: a finished report is static. --}}
    <div class="messages" x-ref="body" @if ($run->isActive()) wire:poll.750ms @endif>
        <div class="row assistant" style="max-width:100%">
            <div class="avatar">@svg('lucide-scan-search')</div>
            <div class="bubble md" style="max-width:100%;flex:1">
                <div class="scan-meta">
                    <span class="badge {{ $run->status === 'failed' ? 'prod' : '' }}">{{ $run->status }}</span>
                    @if ($run->severity)
                        <span class="badge {{ in_array($run->severity, ['high', 'critical']) ? 'prod' : '' }}">severity: {{ $run->severity }}</span>
                    @endif
                    @if ($run->progress)
                        <span class="hint">{{ $run->progress }}</span>
                    @endif
                </div>

                @if ($run->summary)
                    <p><strong>{{ $run->summary }}</strong></p>
                @endif

                @if (filled($run->report))
                    {!! \App\Support\SafeMarkdown::render($run->report) !!}
                @elseif ($run->isActive())
                    <span class="typing"><i></i><i></i><i></i></span>
                    <div class="hint">{{ $run->status === 'queued' ? 'Waiting for a worker to pick the scan up…' : 'The agent is investigating…' }}</div>
                @else
                    <em class="hint">(no report)</em>
                @endif
            </div>
        </div>
    </div>
</div>
