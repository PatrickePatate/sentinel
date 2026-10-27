<div>
<x-ui.card class="h-[calc(100vh-14rem)] min-h-[28rem]" flush>
    <div class="flex h-full flex-col">
        <div class="flex items-center justify-between border-b px-5 py-3">
            <span class="text-sm font-medium">Chat with the agent</span>
            <div class="flex items-center gap-1">
                @can('admin')
                    @if ($machine->unguardedActive())
                        <x-ui.button variant="outline" size="sm" wire:click="closeUnguarded" wire:loading.attr="disabled" wire:target="closeUnguarded">@svg('lucide-lock') End unguarded session</x-ui.button>
                    @elseif (! $machine->isRevoked())
                        <x-ui.button variant="outline" size="sm" wire:loading.attr="disabled" wire:target="openUnguarded" x-on:click="$dispatch('open-modal', 'unguarded')">@svg('lucide-lock-open') Unguarded session</x-ui.button>
                    @endif
                @endcan
                @if ($messages->isNotEmpty())<x-ui.button variant="ghost" size="sm" x-on:click="$store.confirm.ask({ title: 'Clear this conversation?', label: 'Clear', action: () => $wire.clear() })">@svg('lucide-trash-2') Clear</x-ui.button>@endif
            </div>
        </div>
        @if ($machine->unguardedActive())
            <div class="flex items-center gap-2 border-b border-destructive/30 bg-destructive/10 px-5 py-2 text-xs text-destructive"
                 x-data="{ end: {{ $machine->unguarded_until->getTimestampMs() }}, left: '', timer: null, init() { this.tick(); this.timer = setInterval(() => this.tick(), 1000); }, destroy() { clearInterval(this.timer); }, tick() { const s = Math.max(0, Math.round((this.end - Date.now()) / 1000)); this.left = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); if (s === 0) { clearInterval(this.timer); $wire.$refresh(); } } }">
                @svg('lucide-triangle-alert', 'size-4 shrink-0')
                <span class="flex-1"><strong>Unguarded session</strong>: the agent can propose any command, run as root once you approve it. Read each one before approving.</span>
                <span class="shrink-0 rounded-md bg-destructive/15 px-2 py-0.5 font-mono font-medium tabular-nums" x-text="left + ' left'"></span>
            </div>
        @endif

        <div class="flex-1 space-y-5 overflow-y-auto p-5" x-data="follow">
            @forelse ($messages as $chatMessage)
                <div wire:key="m{{ $chatMessage->id }}" @class(['flex gap-3', 'flex-row-reverse' => $chatMessage->role === 'user'])>
                    @if ($chatMessage->role === 'user')
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">@svg('lucide-user', 'size-4')</span>
                    @else
                        <x-ui.agent-avatar :provider="config('sentinel.agent.provider')" :model="config('sentinel.agent.model')" />
                    @endif
                    @if ($chatMessage->role === 'user')
                        <div class="max-w-[85%] whitespace-pre-wrap break-words rounded-xl bg-primary px-4 py-2.5 text-sm text-primary-foreground">{{ $chatMessage->content }}</div>
                    @else
                        <div class="md max-w-[85%] rounded-xl border bg-card px-4 py-2.5 shadow-xs">{!! \App\Support\SafeMarkdown::render($chatMessage->content) !!}</div>
                    @endif
                </div>
            @empty
                <div class="mx-auto flex max-w-xl flex-col items-center gap-2 py-8 text-center" wire:loading.remove wire:target="send">
                    <span class="flex size-11 items-center justify-center rounded-full bg-primary text-primary-foreground">@svg('lucide-shield-check', 'size-5')</span>
                    <h2 class="font-semibold">Sentinel agent</h2>
                    <p class="text-sm text-muted-foreground">Read-only tools by default. Corrective actions are risk-checked and need your approval.</p>
                    <div class="mt-4 grid w-full gap-2 sm:grid-cols-2">
                        @foreach (['How is the disk usage?', 'Check the SSH hardening', 'Any failed services?', 'Summarise recent failed logins'] as $suggestion)
                            <x-ui.button variant="outline" class="h-auto justify-start whitespace-normal py-2 text-left font-normal" :x-on:click="'$wire.message = '.\Illuminate\Support\Js::from($suggestion).'; $wire.send()'">{{ $suggestion }}</x-ui.button>
                        @endforeach
                    </div>
                </div>
            @endforelse

            @if ($commands->isNotEmpty())
                <div class="flex items-center gap-3 text-xs text-muted-foreground"><span class="h-px flex-1 bg-border"></span><span class="flex items-center gap-1.5">@svg('lucide-terminal', 'size-3.5') Proposed commands</span><span class="h-px flex-1 bg-border"></span></div>
            @endif
            @foreach ($commands->reverse() as $command)
                @php $open = $command->status === 'pending' && $machine->unguardedActive() && $command->unguarded_session === $machine->unguarded_session; @endphp
                <div wire:key="c{{ $command->id }}" @class(['space-y-2 rounded-xl border bg-card px-4 py-3 text-sm shadow-xs', 'border-warning/50' => $open, 'border-destructive/30' => ! $open && $command->status === 'failed'])>
                    <div class="flex items-center justify-between gap-2">
                        <span class="flex items-center gap-1.5 font-medium">@svg('lucide-terminal', 'size-4') Command #{{ $command->id }} <span class="font-normal text-muted-foreground">as root</span></span>
                        <x-ui.badge :variant="match ($command->status) { 'executed' => 'success', 'pending' => $open ? 'warning' : 'secondary', 'failed' => 'destructive', default => 'secondary' }">{{ $command->status === 'pending' && ! $open ? 'session ended' : ($command->status === 'pending' ? 'waiting for you' : $command->status) }}</x-ui.badge>
                    </div>
                    <pre class="overflow-x-auto whitespace-pre-wrap break-all rounded-md bg-muted px-3 py-2 font-mono text-xs">{{ $command->arguments['command'] ?? '' }}</pre>
                    <p class="line-clamp-3 text-xs text-muted-foreground" title="{{ $command->reason }}">{{ $command->reason }}</p>
                    @if ($command->output)<pre class="max-h-48 overflow-auto whitespace-pre-wrap break-all rounded-md border px-3 py-2 font-mono text-xs">{{ $command->output }}</pre>@endif
                    @if ($open)
                        @can('approve')
                            <div class="flex gap-2">
                                @can('admin')<x-ui.button size="sm" variant="destructive" wire:click="approveCommand({{ $command->id }})" wire:loading.attr="disabled" wire:target="approveCommand({{ $command->id }})">@svg('lucide-play') Approve and run as root</x-ui.button>@endcan
                                <x-ui.button size="sm" variant="outline" wire:click="rejectCommand({{ $command->id }})" wire:loading.attr="disabled">Reject</x-ui.button>
                            </div>
                        @endcan
                    @elseif ($command->status === 'pending')
                        <p class="text-xs text-muted-foreground">The session ended, so this cannot run. Start a new session and ask the agent to propose it again.</p>
                    @endif
                </div>
            @endforeach

            <div wire:loading.flex wire:target="send" class="hidden flex-col gap-5">
                <div class="flex flex-row-reverse gap-3"><span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted">@svg('lucide-user', 'size-4')</span><div class="max-w-[85%] whitespace-pre-wrap break-words rounded-xl bg-primary px-4 py-2.5 text-sm text-primary-foreground" wire:stream="question"></div></div>
                <div class="flex gap-3"><x-ui.agent-avatar :provider="config('sentinel.agent.provider')" :model="config('sentinel.agent.model')" />
                    <div class="md max-w-[85%] rounded-xl border bg-card px-4 py-2.5 shadow-xs">
                        <div wire:stream="answer"></div>
                        <span class="typing [[wire\:stream=answer]:not(:empty)+&]:hidden"><i></i><i></i><i></i></span>
                        <div class="mt-1 text-xs text-muted-foreground empty:hidden" wire:stream="status"></div>
                    </div></div>
            </div>
        </div>

        <div class="border-t p-4">
            @can('approve')
            <form wire:submit="send" class="flex gap-2">
                <x-ui.input wire:model="message" wire:loading.attr="disabled" wire:target="send" placeholder="{{ $machine->unguardedActive() ? 'Message the agent (unguarded session)…' : 'Message the agent…' }}" maxlength="2000" autocomplete="off" />
                <x-ui.button type="submit" size="icon" wire:loading.attr="disabled" wire:target="send" aria-label="Send">@svg('lucide-send-horizontal')</x-ui.button>
            </form>
            @error('message')<p class="mt-2 text-xs text-destructive">{{ $message }}</p>@enderror
            @else
            <p class="text-center text-sm text-muted-foreground">Your role can read this conversation, not write to it.</p>
            @endcan
            <p class="mt-2 text-center text-[11px] text-muted-foreground">{{ $machine->unguardedActive() ? 'Approved commands run as root. Every command is audited.' : 'The agent can make mistakes. Every command is audited.' }}</p>
        </div>
    </div>
</x-ui.card>

@can('admin')
    <x-ui.modal name="unguarded" title="Start an unguarded session?" description="{{ $machine->name }} · {{ $machine->environment }}">
        {{-- The proof stays in Alpine and goes as a call argument: it is never part of the component's state. --}}
        <div x-data="{ understood: false, proof: '', busy: false }" x-on:open-modal.window="understood = false; proof = ''" class="grid gap-4">
            <ul class="grid gap-2.5 text-sm">
                <li class="flex gap-2.5">@svg('lucide-key-round', 'mt-0.5 size-4 shrink-0 text-muted-foreground')<span>For <strong>{{ (int) config('sentinel.unguarded.lease_minutes') }} minutes</strong> the machine runs commands you approve as root. The lease ends by itself on the machine, and with any reboot.</span></li>
                <li class="flex gap-2.5">@svg('lucide-terminal', 'mt-0.5 size-4 shrink-0 text-muted-foreground')<span>The agent can propose <strong>any shell command</strong>. Nothing runs until you approve that exact command in the chat.</span></li>
                <li class="flex gap-2.5">@svg('lucide-shield-off', 'mt-0.5 size-4 shrink-0 text-destructive')<span>Approved commands are <strong>not risk-checked</strong>: they can delete data or stop services.</span></li>
                <li class="flex gap-2.5">@svg('lucide-bug', 'mt-0.5 size-4 shrink-0 text-destructive')<span>The agent reads text from the machine, which an attacker may have planted. Read every command before approving.</span></li>
                <li class="flex gap-2.5">@svg('lucide-scroll-text', 'mt-0.5 size-4 shrink-0 text-muted-foreground')<span>The session and every command are recorded in the audit log.</span></li>
            </ul>
            <label class="flex cursor-pointer items-start gap-2.5 rounded-md border bg-muted/50 p-3 text-sm">
                <input type="checkbox" x-model="understood" class="mt-0.5 size-4 shrink-0 accent-destructive">
                <span>I understand, and I want to start an unguarded session on this {{ $machine->environment }} machine.</span>
            </label>
            @if (auth()->user()->hasTwoFactor())
                <label class="grid gap-1.5 text-sm"><span class="font-medium">Authentication code</span>
                    <x-ui.input x-model="proof" inputmode="numeric" autocomplete="one-time-code" maxlength="10" placeholder="123456" /></label>
            @else
                <label class="grid gap-1.5 text-sm"><span class="font-medium">Your password</span>
                    <x-ui.input type="password" x-model="proof" autocomplete="current-password" /></label>
            @endif
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <x-ui.button variant="outline" x-on:click="$dispatch('close-modal')">Cancel</x-ui.button>
                <x-ui.button variant="destructive" x-bind:disabled="! understood || proof === '' || busy" x-on:click="busy = true; $wire.openUnguarded(proof).then(started => { busy = false; proof = ''; if (started) $dispatch('close-modal') })">@svg('lucide-lock-open') Start unguarded session</x-ui.button>
            </div>
        </div>
    </x-ui.modal>
@endcan
</div>
