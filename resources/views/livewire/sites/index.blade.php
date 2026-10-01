<div @realtime wire:poll.60s @else wire:poll.15s @endrealtime class="space-y-6">
    <x-ui.page-header title="Sites" description="Public URLs Sentinel checks from outside every five minutes: status, speed and certificate expiry.">
        <x-slot:actions>
            <x-ui.button x-on:click="$dispatch('open-modal', 'site')">@svg('lucide-plus') Add a site</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card flush>
        @if ($sites->isEmpty())
            <x-ui.empty icon="lucide-globe" title="No site monitored" description="Add a URL and pick the machine that serves it. When it stays down, you are alerted and the machine can be analyzed." />
        @else
            <x-ui.table>
                <thead><tr><th>Site</th><th>Machine</th><th>Status</th><th>Certificate</th><th>If it goes down</th><th></th></tr></thead>
                <tbody>
                    @foreach ($sites as $site)
                        <tr wire:key="s{{ $site->id }}">
                            <td class="max-w-xs"><span class="block truncate font-mono text-xs">{{ $site->url }}</span><span class="text-xs text-muted-foreground">{{ $site->checked_at ? 'checked '.$site->checked_at->diffForHumans() : 'not checked yet' }}</span></td>
                            <td><a href="{{ route('machines.show', $site->machine) }}" wire:navigate class="hover:underline">{{ $site->machine->name }}</a></td>
                            <td>
                                @if (! $site->checked_at)<x-ui.badge>Pending</x-ui.badge>
                                @elseif ($site->ok)<x-ui.badge variant="success">Up</x-ui.badge> <span class="text-xs text-muted-foreground">{{ $site->status_code }} · {{ $site->response_ms }} ms</span>
                                @else<x-ui.badge variant="destructive">Down</x-ui.badge> <span class="text-xs text-muted-foreground">{{ $site->error }} @if ($site->failures > 1)· {{ $site->failures }} checks @endif</span>@endif
                            </td>
                            <td class="text-xs text-muted-foreground">{{ $site->cert_expires_at ? ($site->cert_expires_at->isPast() ? 'expired' : 'expires '.$site->cert_expires_at->diffForHumans()) : '—' }}</td>
                            <td class="text-xs">
                                <label class="flex items-center gap-2"><input type="checkbox" class="size-4 rounded border-input" @checked($site->analyze_on_down) wire:click="toggleAnalysis({{ $site->id }})"> Analyze the machine</label>
                                @if ($site->analyze_on_down && ! $willAnalyze[$site->id])
                                    <span class="text-muted-foreground">Needs the <a class="underline" href="{{ route('machines.edit', $site->machine) }}" wire:navigate>web server analysis</a> enabled on the machine.</span>
                                @endif
                            </td>
                            <td class="space-x-1 text-right">
                                <x-ui.button size="sm" variant="outline" wire:click="checkNow({{ $site->id }})" wire:loading.attr="disabled" wire:target="checkNow({{ $site->id }})">@svg('lucide-refresh-cw') Check</x-ui.button>
                                <x-ui.button size="sm" variant="ghost" :x-on:click="'$store.confirm.ask({ title: \'Stop monitoring this site?\', message: '.\Illuminate\Support\Js::from($site->url).', label: \'Remove\', destructive: true, action: () => $wire.remove('.$site->id.') })'">@svg('lucide-trash-2')</x-ui.button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.card>

    <x-ui.modal name="site" title="Monitor a site" description="Sentinel requests the URL every five minutes. After two failures in a row you are alerted once.">
        <form wire:submit="add" id="site-form" class="grid gap-4">
            <x-ui.field label="Machine" name="machine_id" hint="The server that serves it: it is the one analyzed when the site goes down.">
                <x-ui.select wire:model="machine_id"><option value="">Choose…</option>@foreach ($machines as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</x-ui.select>
            </x-ui.field>
            <x-ui.field label="URL" name="url"><x-ui.input wire:model="url" placeholder="https://shop.example.org/" /></x-ui.field>
            <x-ui.switch wire:model="analyze_on_down" label="Start an analysis when it goes down" description="A web server analysis of the machine (it may start crashed services or roll back a broken configuration through the risk gate). Needs the web server analysis enabled on the machine." />
        </form>
        <x-slot:footer>
            <x-ui.button variant="outline" x-on:click="$dispatch('close-modal')">Cancel</x-ui.button>
            <x-ui.button type="submit" form="site-form" wire:loading.attr="disabled">Add and check</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
