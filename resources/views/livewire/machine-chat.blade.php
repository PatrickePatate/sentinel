<div style="display:flex;flex-direction:column;height:100%;padding:12px;gap:8px">
    <div style="display:flex;justify-content:space-between;align-items:center;color:var(--muted);font-size:12px">
        <span>Agent on <strong>{{ $machine->name }}</strong> ({{ $machine->environment }}) — read-only tools; corrective actions are risk-checked.</span>
        @if ($messages->isNotEmpty())
            <button type="button" wire:click="clear" wire:confirm="Clear this conversation?" style="background:none;border:0;color:var(--accent);cursor:pointer">Clear</button>
        @endif
    </div>

    <div style="flex:1;overflow-y:auto;display:flex;flex-direction:column;gap:8px" id="messages">
        @forelse ($messages as $chatMessage)
            <div wire:key="m{{ $chatMessage->id }}" style="max-width:85%;padding:8px 12px;border-radius:10px;white-space:pre-wrap;word-break:break-word;background:var({{ $chatMessage->role === 'user' ? '--user' : '--bot' }});align-self:{{ $chatMessage->role === 'user' ? 'flex-end' : 'flex-start' }}">{{ $chatMessage->content }}</div>
        @empty
            <p style="color:var(--muted)">Ask something, e.g. “How is the disk usage?” or “Check the SSH hardening”.</p>
        @endforelse

        <div wire:loading.flex wire:target="send" style="display:none;flex-direction:column;gap:8px">
            <div wire:stream="question" style="max-width:85%;padding:8px 12px;border-radius:10px;white-space:pre-wrap;word-break:break-word;background:var(--user);align-self:flex-end"></div>
            <div style="max-width:85%;padding:8px 12px;border-radius:10px;background:var(--bot);align-self:flex-start">
                <div wire:stream="answer" style="white-space:pre-wrap;word-break:break-word"></div>
                <div wire:stream="status" style="color:var(--muted);font-size:12px"></div>
            </div>
        </div>
    </div>

    <form wire:submit="send" style="display:flex;gap:8px">
        <input type="text" wire:model="message" wire:loading.attr="disabled" wire:target="send" placeholder="Message the agent" maxlength="2000" autocomplete="off"
               style="flex:1;padding:8px 10px;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--fg)">
        <button type="submit" wire:loading.attr="disabled" wire:target="send"
                style="padding:8px 16px;border:0;border-radius:8px;background:var(--accent);color:#fff;cursor:pointer">Send</button>
    </form>
    @error('message') <span style="color:#dc2626">{{ $message }}</span> @enderror
</div>
