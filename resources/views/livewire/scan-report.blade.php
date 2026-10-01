<div class="space-y-6" @if ($active) @realtime wire:poll.15s @else wire:poll.750ms @endrealtime @endif>
    <x-ui.page-header :title="$run->machine->name">
        <x-slot:actions>
            <x-ui.live :active="$active" :label="$active ? 'Streaming' : 'Finished'" />
            <x-ui.button variant="outline" :href="route('machines.show', $run->machine)">@svg('lucide-server') Machine</x-ui.button>
            <x-ui.button variant="outline" :href="route('scans.index')">@svg('lucide-arrow-left') All scans</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="space-y-6 xl:col-span-2">
            @include('livewire.partials.scan-turn', ['turn' => $run, 'isScan' => true])

            @foreach ($run->followUps as $followUp)
                <div wire:key="q{{ $followUp->id }}" class="flex justify-end"><div class="max-w-[85%] rounded-xl bg-primary px-4 py-2.5 text-sm text-primary-foreground">{{ $followUp->objective }}</div></div>
                <div wire:key="a{{ $followUp->id }}">@include('livewire.partials.scan-turn', ['turn' => $followUp, 'isScan' => false])</div>
            @endforeach

            @unless ($active)
                <x-ui.card>
                    <form wire:submit="ask" class="flex gap-2">
                        <x-ui.input wire:model="message" wire:loading.attr="disabled" placeholder="Ask the agent to act on this scan…" maxlength="2000" autocomplete="off" />
                        <x-ui.button type="submit" size="icon" wire:loading.attr="disabled" aria-label="Send">@svg('lucide-send-horizontal')</x-ui.button>
                    </form>
                    @error('message')<p class="mt-2 text-xs text-destructive">{{ $message }}</p>@enderror
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <x-ui.button variant="outline" size="sm" x-on:click="$store.confirm.ask({ title: 'Fix what the agent found?', message: 'It will request the corrective actions it recommended. They still go through the risk gate: medium-risk ones wait for your approval.', label: 'Fix it', action: () => $wire.fixFindings() })">@svg('lucide-wrench') Fix what you found</x-ui.button>
                        <p class="text-xs text-muted-foreground">Actions are listed on this page and wait for approval unless the risk gate allows them.</p>
                    </div>
                </x-ui.card>
            @endunless
        </div>

        <div class="space-y-6">
            @php($actions = $run->pendingActions->concat($run->followUps->flatMap->pendingActions)->sortByDesc('id'))
            @if ($actions->isNotEmpty())
                <x-ui.card title="Actions from this scan" flush>
                    @foreach ($actions as $action)
                        <div wire:key="p{{ $action->id }}" class="space-y-2 border-b px-6 py-4 last:border-0">
                            <div class="flex items-center justify-between gap-2"><span class="text-sm font-medium">{{ $action->action }}</span><div class="flex gap-1"><x-ui.status-badge :status="$action->risk" /><x-ui.status-badge :status="$action->status" /></div></div>
                            <code class="block break-all rounded bg-muted px-2 py-1 text-xs">{{ $action->command }}</code>
                            <p class="text-xs text-muted-foreground">{{ $action->reason }}</p>
                            @if ($action->status === 'pending')
                                <div class="flex gap-2">
                                    <x-ui.button size="sm" :x-on:click="'$store.confirm.ask({ title: \'Run this exact command?\', message: '.\Illuminate\Support\Js::from($action->command).', label: \'Approve and run\', action: () => $wire.approve('.$action->id.') })'">Approve and run</x-ui.button>
                                    <x-ui.button size="sm" variant="outline" wire:click="reject({{ $action->id }})">Reject</x-ui.button>
                                </div>
                            @elseif ($action->output)
                                <pre class="max-h-40 overflow-auto rounded bg-muted p-2 text-xs">{{ $action->output }}</pre>
                            @endif
                        </div>
                    @endforeach
                </x-ui.card>
            @endif

            <x-ui.card title="Steps" description="What the agent ran, as it happens" flush>
                @forelse ($steps as $step)
                    <div wire:key="s{{ $step->id }}" class="flex items-start gap-3 border-b px-6 py-2.5 last:border-0">
                        <span @class(['mt-1.5 size-2 shrink-0 rounded-full', 'bg-success' => in_array($step->event, ['ok', 'action_executed']), 'bg-destructive' => in_array($step->event, ['failed', 'rejected', 'action_refused']), 'bg-warning' => in_array($step->event, ['action_proposed', 'action_pending']), 'bg-muted-foreground' => ! in_array($step->event, ['ok', 'action_executed', 'failed', 'rejected', 'action_refused', 'action_proposed', 'action_pending'])])></span>
                        <div class="min-w-0"><p class="text-sm font-medium">{{ $step->description }} @if (isset($step->properties['exit_code']) && $step->properties['exit_code'] !== 0)<span class="text-xs font-normal text-muted-foreground">exit {{ $step->properties['exit_code'] }}</span>@endif</p><p class="truncate font-mono text-xs text-muted-foreground" title="{{ $step->properties['command'] }}">{{ $step->properties['command'] }}</p></div>
                    </div>
                @empty
                    <x-ui.empty icon="lucide-terminal" title="No command yet" description="Steps appear here as the agent works." />
                @endforelse
            </x-ui.card>
        </div>
    </div>
</div>
