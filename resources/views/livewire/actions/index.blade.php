<div @realtime wire:poll.30s @else wire:poll.4s @endrealtime class="space-y-6">
    <x-ui.page-header title="Pending actions" description="Corrective actions the agent asked for. Nothing runs without the risk gate, and medium risk needs you.">
        <x-slot:actions><x-ui.live /></x-slot:actions>
    </x-ui.page-header>

    <div class="flex flex-wrap gap-1">
        @foreach (['pending' => 'Awaiting approval', 'executed' => 'Executed', 'rejected' => 'Rejected', '' => 'All'] as $key => $label)
            <x-ui.button size="sm" :variant="$status === (string) $key ? 'default' : 'outline'" wire:click="$set('status', '{{ $key }}')">{{ $label }} @if ($key !== '' && ($counts[$key] ?? 0))<span class="opacity-70">{{ $counts[$key] }}</span>@endif</x-ui.button>
        @endforeach
    </div>

    <div class="space-y-3">
        @forelse ($actions as $action)
            <x-ui.card wire:key="a{{ $action->id }}">
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('machines.show', $action->machine) }}" wire:navigate class="font-semibold hover:underline">{{ $action->machine->name }}</a>
                    <x-ui.badge variant="outline">{{ $action->action }}</x-ui.badge>
                    <x-ui.status-badge :status="$action->risk" />
                    <x-ui.status-badge :status="$action->status" />
                    <span class="ml-auto text-xs text-muted-foreground">{{ $action->created_at->diffForHumans() }}</span>
                </div>
                <code class="mt-3 block break-all rounded-md bg-muted px-3 py-2 text-xs">{{ $action->command }}</code>
                <p class="mt-2 text-sm text-muted-foreground"><span class="font-medium text-foreground">Why held:</span> {{ $action->reason }}</p>
                @if ($action->output)<pre class="mt-3 max-h-48 overflow-auto rounded-md border bg-muted/50 p-3 text-xs">{{ $action->output }}</pre>@endif
                @if ($action->status === 'pending')
                    <x-slot:footer>
                        <x-ui.button size="sm" :x-on:click="'$store.confirm.ask({ title: \'Run this exact command now?\', message: '.\Illuminate\Support\Js::from($action->machine->name.': '.$action->command).', label: \'Approve and run\', action: () => $wire.approve('.$action->id.') })'">@svg('lucide-check') Approve and run</x-ui.button>
                        @if (in_array($action->action, $trustable, true) && ! $action->machine->trusts($action->action))
                            <x-ui.button size="sm" variant="outline" :x-on:click="'$store.confirm.ask({ title: \'Always allow this action on this machine?\', message: '.\Illuminate\Support\Js::from('Runs it now, then lets the agent run '.$action->action.' on '.$action->machine->name.' without asking again (the risk gate classifier is skipped; the autonomous action quota and the flapping guard still apply). You can revoke this on the machine page.').', label: \'Run and always allow\', action: () => $wire.approve('.$action->id.', true) })'" title="@if ($ranBefore->get($action->machine_id.':'.$action->action, 0) > 0)Approved and run successfully {{ $ranBefore->get($action->machine_id.':'.$action->action) }} time(s) before on this machine @endif">@svg('lucide-shield-check') Approve and always allow</x-ui.button>
                        @endif
                        <x-ui.button size="sm" variant="outline" wire:click="reject({{ $action->id }})">@svg('lucide-x') Reject</x-ui.button>
                    </x-slot:footer>
                @endif
            </x-ui.card>
        @empty
            <x-ui.card><x-ui.empty icon="lucide-check" title="Nothing here" description="Actions requested by the agent show up here the moment they are held." /></x-ui.card>
        @endforelse
    </div>
    {{ $actions->links() }}
</div>
