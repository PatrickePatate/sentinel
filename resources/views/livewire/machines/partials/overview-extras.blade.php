@if ($health)
    <x-ui.card title="Health" description="Sampled by Sentinel every few minutes over SSH, last 7 days. Hover a line for the values.">
        <div class="grid gap-x-8 gap-y-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ($health as $name => $metric)
                @php($last = end($metric['points'])['value'])
                <div wire:key="m{{ $name }}" class="space-y-1">
                    <div class="flex items-baseline justify-between gap-2"><span class="text-sm text-muted-foreground">{{ $metric['label'] }}</span><span class="text-sm font-medium tabular-nums">{{ $name === 'reboot_required' ? ($last ? 'Yes' : 'No') : rtrim(rtrim(number_format($last, 1), '0'), '.').$metric['unit'] }}</span></div>
                    <x-ui.sparkline :points="$metric['points']" :unit="$metric['unit']" :max="$metric['unit'] === '%' ? 100 : null" class="w-full" />
                    @if ($metric['trend'] && abs($metric['trend']['per_day']) >= 0.05)
                        <p @class(['text-xs', 'text-warning' => $metric['trend']['days_left'] !== null && $metric['trend']['days_left'] <= 14, 'text-muted-foreground' => ! ($metric['trend']['days_left'] !== null && $metric['trend']['days_left'] <= 14)])>{{ sprintf('%+.1f points a day', $metric['trend']['per_day']) }}@if ($metric['trend']['days_left'] !== null), full in about {{ $metric['trend']['days_left'] }} days @endif</p>
                    @endif
                </div>
            @endforeach
        </div>
    </x-ui.card>
@endif

@if ($suggestions->isNotEmpty())
    <x-ui.card title="Suggested memory notes" description="Suggested by the agent from what it read on the machine, which an attacker may have written into logs. Accepted notes are trusted by every future scan: check them against the scan.">
        <ul class="divide-y">
            @foreach ($suggestions as $suggestion)
                <li class="flex items-center gap-3 py-2 text-sm" wire:key="note{{ $suggestion->id }}">
                    <span class="flex-1">{{ $suggestion->note }}</span>
                    @if ($suggestion->agent_run_id)
                        <a href="{{ route('scans.show', $suggestion->agent_run_id) }}" wire:navigate class="text-xs text-muted-foreground underline">From scan #{{ $suggestion->agent_run_id }}</a>
                    @endif
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
