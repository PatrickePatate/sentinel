<div class="chat" x-data
     x-init="const el = $refs.messages; const down = () => el.scrollTo({ top: el.scrollHeight }); down(); new MutationObserver(down).observe(el, { childList: true, subtree: true, characterData: true })">
    <div class="header">
        <div class="header-title">
            @svg('lucide-server')
            <span class="name">{{ $machine->name }}</span>
            <span class="badge {{ $machine->environment === 'production' ? 'prod' : '' }}">{{ $machine->environment }}</span>
        </div>
        @if ($messages->isNotEmpty())
            <button type="button" class="btn btn-ghost" wire:click="clear" wire:confirm="Clear this conversation?">
                @svg('lucide-trash-2') Clear
            </button>
        @endif
    </div>

    <div class="messages" x-ref="messages">
        @forelse ($messages as $chatMessage)
            <div wire:key="m{{ $chatMessage->id }}" class="row {{ $chatMessage->role === 'user' ? 'user' : 'assistant' }}">
                <div class="avatar">@svg($chatMessage->role === 'user' ? 'lucide-user' : 'lucide-bot')</div>
                <div class="bubble">{{ $chatMessage->content }}</div>
            </div>
        @empty
            <div class="empty" wire:loading.remove wire:target="send">
                <div class="avatar">@svg('lucide-shield-check')</div>
                <h2>Sentinel agent</h2>
                <p>Read-only tools by default. Corrective actions are risk-checked and need your approval.</p>
                <div class="suggestions">
                    @foreach (['How is the disk usage?', 'Check the SSH hardening', 'Any failed services?', 'Summarise recent failed logins'] as $suggestion)
                        <button type="button" class="btn btn-outline" x-on:click="$wire.message = @js($suggestion); $wire.send()">{{ $suggestion }}</button>
                    @endforeach
                </div>
            </div>
        @endforelse

        <div wire:loading.flex wire:target="send" style="display:none;flex-direction:column;gap:1.25rem">
            <div class="row user">
                <div class="avatar">@svg('lucide-user')</div>
                <div class="bubble" wire:stream="question"></div>
            </div>
            <div class="row assistant">
                <div class="avatar">@svg('lucide-bot')</div>
                <div class="bubble">
                    <div wire:stream="answer" style="white-space:pre-wrap;word-break:break-word"></div>
                    <div class="typing-wrap"><span class="typing"><i></i><i></i><i></i></span></div>
                    <div class="status" wire:stream="status"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="composer">
        <form wire:submit="send">
            <input type="text" class="input" wire:model="message" wire:loading.attr="disabled" wire:target="send" placeholder="Message the agent…" maxlength="2000" autocomplete="off" autofocus>
            <button type="submit" class="btn btn-primary btn-icon" wire:loading.attr="disabled" wire:target="send" aria-label="Send">@svg('lucide-send-horizontal')</button>
        </form>
        @error('message') <span class="error">{{ $message }}</span> @enderror
        <div class="footnote">The agent can make mistakes. Every command is audited.</div>
    </div>
</div>
