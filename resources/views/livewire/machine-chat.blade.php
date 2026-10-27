<x-ui.card class="h-[calc(100vh-14rem)] min-h-[28rem]" flush>
    <div class="flex h-full flex-col">
        <div class="flex items-center justify-between border-b px-5 py-3">
            <span class="text-sm font-medium">Chat with the agent</span>
            <div class="flex items-center gap-1">
                @can('admin')
                    @if ($machine->unguardedActive())
                        <x-ui.button variant="outline" size="sm" wire:click="toggleUnguarded" wire:loading.attr="disabled" wire:target="toggleUnguarded">@svg('lucide-lock') End unguarded session</x-ui.button>
                    @elseif (! $machine->isRevoked())
                        <x-ui.button variant="outline" size="sm" wire:loading.attr="disabled" wire:target="toggleUnguarded" x-on:click="$store.confirm.ask({
                            title: 'Start an unguarded session on {{ e($machine->name) }}?',
                            message: 'For {{ (int) config('sentinel.unguarded.lease_minutes') }} minutes the agent can propose ANY shell command, run as root on this {{ e($machine->environment) }} machine. Nothing runs until you approve that exact command in the chat, but an approved command is not risk-checked: it can delete data or stop services, and the agent reads text from the machine that an attacker may have planted. The session is audited and ends by itself on the machine.',
                            label: 'Start unguarded session',
                            destructive: true,
                            action: () => $wire.toggleUnguarded()
                        })">@svg('lucide-lock-open') Unguarded session</x-ui.button>
                    @endif
                @endcan
                @if ($messages->isNotEmpty())<x-ui.button variant="ghost" size="sm" x-on:click="$store.confirm.ask({ title: 'Clear this conversation?', label: 'Clear', action: () => $wire.clear() })">@svg('lucide-trash-2') Clear</x-ui.button>@endif
            </div>
        </div>
        @if ($machine->unguardedActive())
            <div class="flex items-center gap-2 border-b border-destructive/30 bg-destructive/10 px-5 py-2 text-xs text-destructive" wire:poll.30s>
                @svg('lucide-triangle-alert', 'size-4 shrink-0')
                <span><strong>Unguarded session</strong> until {{ $machine->unguarded_until->format('H:i') }}: the agent can propose any command as root. Read each one before approving.</span>
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

            @foreach ($commands->reverse() as $command)
                <div wire:key="c{{ $command->id }}" class="ml-11 max-w-[85%] space-y-2 rounded-xl border border-destructive/30 bg-card px-4 py-3 text-sm shadow-xs">
                    <div class="flex items-center justify-between gap-2">
                        <span class="flex items-center gap-1.5 font-medium">@svg('lucide-terminal', 'size-4') Command #{{ $command->id }} <span class="font-normal text-muted-foreground">(root)</span></span>
                        <x-ui.badge :variant="match ($command->status) { 'executed' => 'success', 'pending' => 'warning', default => 'secondary' }">{{ $command->status }}</x-ui.badge>
                    </div>
                    <pre class="overflow-x-auto whitespace-pre-wrap break-all rounded-md bg-muted px-3 py-2 font-mono text-xs">{{ $command->arguments['command'] ?? '' }}</pre>
                    <p class="text-xs text-muted-foreground">{{ $command->reason }}</p>
                    @if ($command->output)<pre class="max-h-48 overflow-auto whitespace-pre-wrap break-all rounded-md border px-3 py-2 font-mono text-xs">{{ $command->output }}</pre>@endif
                    @if ($command->status === 'pending')
                        @can('approve')
                            <div class="flex gap-2">
                                <x-ui.button size="sm" variant="destructive" wire:click="approveCommand({{ $command->id }})" wire:loading.attr="disabled" wire:target="approveCommand({{ $command->id }})">Approve and run as root</x-ui.button>
                                <x-ui.button size="sm" variant="outline" wire:click="rejectCommand({{ $command->id }})" wire:loading.attr="disabled">Reject</x-ui.button>
                            </div>
                        @endcan
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
                <x-ui.input wire:model="message" wire:loading.attr="disabled" wire:target="send" placeholder="Message the agent…" maxlength="2000" autocomplete="off" />
                <x-ui.button type="submit" size="icon" wire:loading.attr="disabled" wire:target="send" aria-label="Send">@svg('lucide-send-horizontal')</x-ui.button>
            </form>
            @error('message')<p class="mt-2 text-xs text-destructive">{{ $message }}</p>@enderror
            @else
            <p class="text-center text-sm text-muted-foreground">Your role can read this conversation, not write to it.</p>
            @endcan
            <p class="mt-2 text-center text-[11px] text-muted-foreground">The agent can make mistakes. Every command is audited.</p>
        </div>
    </div>
</x-ui.card>
