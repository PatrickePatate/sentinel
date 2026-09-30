<div class="chat" x-data
     x-init="const el = $refs.body; let follow = true; el.addEventListener('scroll', () => follow = el.scrollHeight - el.scrollTop - el.clientHeight < 60); new MutationObserver(() => follow && el.scrollTo({ top: el.scrollHeight })).observe(el, { childList: true, subtree: true, characterData: true })">
    {{-- Only poll while the scan or a follow-up is active: a finished thread is static. --}}
    <div class="messages" x-ref="body" @if ($active) wire:poll.750ms @endif>
        @include('livewire.partials.scan-turn', ['turn' => $run, 'icon' => 'lucide-scan-search', 'isScan' => true])

        @foreach ($run->followUps as $followUp)
            <div wire:key="q{{ $followUp->id }}" class="row user">
                <div class="avatar">@svg('lucide-user')</div>
                <div class="bubble">{{ $followUp->objective }}</div>
            </div>
            <div wire:key="a{{ $followUp->id }}">
                @include('livewire.partials.scan-turn', ['turn' => $followUp, 'icon' => 'lucide-bot', 'isScan' => false])
            </div>
        @endforeach
    </div>

    @unless ($active)
        <div class="composer">
            <form wire:submit="ask">
                <input type="text" class="input" wire:model="message" wire:loading.attr="disabled" placeholder="Ask the agent to act on this scan…" maxlength="2000" autocomplete="off">
                <button type="submit" class="btn btn-primary btn-icon" wire:loading.attr="disabled" aria-label="Send">@svg('lucide-send-horizontal')</button>
            </form>
            <div class="suggestions" style="margin-top:.5rem">
                <button type="button" class="btn btn-outline" wire:click="fixFindings" wire:loading.attr="disabled"
                        wire:confirm="The agent will request the corrective actions it recommended. They still go through the risk gate: medium-risk ones wait for your approval.">
                    @svg('lucide-wrench') Fix what you found
                </button>
            </div>
            @error('message') <span class="error">{{ $message }}</span> @enderror
            <div class="footnote">Actions requested or proposed here are listed with this scan and wait for approval unless the risk gate allows them.</div>
        </div>
    @endunless
</div>
