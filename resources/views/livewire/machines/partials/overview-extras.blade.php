@if ($suggestions->isNotEmpty())
    <x-ui.card title="Suggested memory notes" description="Facts the agent learned about this machine. Nothing is added without you.">
        <ul class="divide-y">
            @foreach ($suggestions as $suggestion)
                <li class="flex items-center gap-3 py-2 text-sm" wire:key="note{{ $suggestion->id }}">
                    <span class="flex-1">{{ $suggestion->note }}</span>
                    <x-ui.button size="sm" wire:click="acceptNote({{ $suggestion->id }})">@svg('lucide-check') Add</x-ui.button>
                    <x-ui.button size="sm" variant="outline" wire:click="dismissNote({{ $suggestion->id }})">Dismiss</x-ui.button>
                </li>
            @endforeach
        </ul>
    </x-ui.card>
@endif

@if ($sites->isNotEmpty() || $disk || $machine->trusted_actions)
    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Public sites" description="Checked from Sentinel every five minutes. Manage them on the Sites page." class="lg:col-span-2">
            @forelse ($sites as $site)
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b py-2 text-sm last:border-0" wire:key="site{{ $site->id }}">
                    <x-ui.badge :variant="$site->ok ? 'outline' : 'destructive'">{{ $site->ok ? 'Up' : 'Down' }}</x-ui.badge>
                    <span class="min-w-0 flex-1 truncate font-mono text-xs">{{ $site->url }}</span>
                    <span class="text-xs text-muted-foreground">
                        @if ($site->ok){{ $site->status_code }} · {{ $site->response_ms }} ms @else{{ $site->error }}@endif
                        @if ($site->cert_expires_at) · certificate {{ $site->cert_expires_at->isPast() ? 'expired' : 'expires '.$site->cert_expires_at->diffForHumans() }}@endif
                    </span>
                </div>
            @empty
                <p class="text-sm text-muted-foreground">No site is monitored. <a class="underline" href="{{ route('sites.index') }}" wire:navigate>Add one</a>.</p>
            @endforelse
        </x-ui.card>

        <x-ui.card title="Trends and trust">
            <div class="space-y-4 text-sm">
                @if ($disk)
                    <div><p class="text-muted-foreground">Root filesystem</p><p>{{ round($disk['percent']) }}% used, {{ sprintf('%+.1f', $disk['per_day']) }} points per day @if ($disk['days_left'] !== null), full in about {{ $disk['days_left'] }} days @endif</p></div>
                @endif
                @if ($machine->trusted_actions)
                    <div><p class="mb-1 text-muted-foreground">Actions allowed without asking</p>
                        @foreach ($machine->trusted_actions as $trusted)
                            <div class="flex items-center justify-between gap-2 py-1"><x-ui.badge variant="outline">{{ $trusted }}</x-ui.badge><x-ui.button size="sm" variant="ghost" wire:click="revokeTrust('{{ $trusted }}')">Revoke</x-ui.button></div>
                        @endforeach
                    </div>
                @endif
            </div>
        </x-ui.card>
    </div>
@endif
