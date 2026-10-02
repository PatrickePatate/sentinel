<div class="space-y-6">
    <x-ui.page-header :title="$machine->name">
        <x-slot:actions>
            <x-ui.badge :variant="$machine->environment === 'production' ? 'outline' : 'secondary'">{{ $machine->environment }}</x-ui.badge>
            @if ($machine->isRevoked())<x-ui.badge variant="destructive">Revoked</x-ui.badge>@endif
            @if ($machine->underPlannedWork())<x-ui.badge variant="warning" dot>Planned work until {{ $machine->maintenance_until->format('H:i') }}</x-ui.badge>@elseif ($machine->inMaintenanceWindow())<x-ui.badge variant="info" dot>Maintenance window</x-ui.badge>@endif
            @can('admin')<x-ui.button variant="outline" :href="route('machines.edit', $machine)">@svg('lucide-pencil') Edit</x-ui.button>@endcan
            @can('approve')
            <x-ui.button x-on:click="$dispatch('open-modal', 'scan')" :disabled="$machine->isRevoked() || ! $machine->host_key_fingerprint">@svg('lucide-scan-search') Run a scan</x-ui.button>
            <x-ui.dropdown>
                <x-slot:trigger><x-ui.button variant="outline" size="icon" aria-label="More">@svg('lucide-ellipsis')</x-ui.button></x-slot:trigger>
                @if ($machine->underPlannedWork())
                    <x-ui.dropdown-item wire:click="endPlannedWork">@svg('lucide-bell') End planned work</x-ui.dropdown-item>
                @else
                    @foreach (\App\Livewire\Machines\Show::PLANNED_WORK_HOURS as $hours)
                        <x-ui.dropdown-item wire:click="startPlannedWork({{ $hours }})">@svg('lucide-bell-off') Planned work: mute alerts {{ $hours }}h</x-ui.dropdown-item>
                    @endforeach
                @endif
                @can('admin')
                @if ($machine->isRevoked())
                    <x-ui.dropdown-item wire:click="restore">@svg('lucide-undo-2') Lift revocation</x-ui.dropdown-item>
                @else
                    <x-ui.dropdown-item destructive x-on:click="$store.confirm.ask({ title: 'Revoke access?', message: 'Sentinel is blocked from this machine at once and pending actions are cancelled. You still have to run the revocation script on the machine.', label: 'Revoke', destructive: true, action: () => $wire.revoke() })">@svg('lucide-ban') Revoke access</x-ui.dropdown-item>
                @endif
                <x-ui.dropdown-item destructive x-on:click="$store.confirm.ask({ title: 'Delete this machine?', message: 'Its scans and history are deleted too.', label: 'Delete', destructive: true, action: () => $wire.delete() })">@svg('lucide-trash-2') Delete</x-ui.dropdown-item>
                @endcan
            </x-ui.dropdown>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.tabs model="tab" :tabs="\App\Livewire\Machines\Show::TABS" :current="$tab" />

    @if ($tab === 'overview')
        <div class="grid gap-6 lg:grid-cols-3">
            <x-ui.card title="Details" class="lg:col-span-2">
                <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-muted-foreground">Address</dt><dd class="font-mono">{{ $machine->username.'@'.$machine->host.':'.$machine->port }}</dd></div>
                    <div><dt class="text-muted-foreground">Pinned host key</dt><dd class="break-all font-mono text-xs">{{ $machine->host_key_fingerprint ?: 'Not pinned: the agent cannot connect' }}</dd></div>
                    @foreach ($machine->availableScanProfiles() as $key => $profile)
                        @php($last = $machine->{$profile['last_column']})
                        <div><dt class="text-muted-foreground">Autonomous {{ strtolower($profile['label']) }}</dt><dd>{{ $machine->scanInterval($key) ? ($profile['frequencies'][$machine->scanInterval($key)] ?? 'custom').($last ? ', last queued '.$last->diffForHumans() : ', not run yet') : 'Manual only' }}</dd></div>
                    @endforeach
                    <div><dt class="text-muted-foreground">Web server analysis</dt><dd>@if ($machine->webserver_enabled)On, AI check {{ $machine->fullCheckHours() ? 'every '.$machine->fullCheckHours().'h when healthy' : 'only when the quick check finds a problem' }} @else Off: <a class="underline" href="{{ route('machines.edit', $machine) }}" wire:navigate>enable it</a> @endif</dd></div>
                    <div><dt class="text-muted-foreground">Open issues</dt><dd>@if ($openFindings->isEmpty())None @else<a class="underline" href="{{ route('findings.index', ['machine' => $machine->id]) }}" wire:navigate>{{ $openFindings->count() }}</a>, worst {{ $openFindings->sortByDesc(fn ($f) => $f->severityLevel()->rank())->first()->severity }}@endif</dd></div>
                    <div><dt class="text-muted-foreground">Maintenance window</dt><dd>@if ($window = $machine->maintenanceWindow()){{ $machine->inMaintenanceWindow() ? 'Open now, until '.$window[1]->format('H:i') : 'Next '.$window[0]->format('D M d, H:i') }} ({{ $machine->maintenance_minutes }} min)@else None: <a class="underline" href="{{ route('machines.edit', $machine) }}" wire:navigate>set one</a>@endif</dd></div>
                    <div><dt class="text-muted-foreground">Autonomous low-risk actions</dt><dd>{{ $machine->isRevoked() ? 'Revoked' : ($machine->autonomy_enabled ? 'Enabled' : 'Off') }}</dd></div>
                </dl>
                <div class="mt-5 border-t pt-4 text-sm">
                    <div class="flex items-center justify-between"><span class="text-muted-foreground">Memory</span><a class="text-xs underline" href="{{ route('machines.edit', $machine) }}" wire:navigate>Edit</a></div>
                    @if (filled($machine->memory))<p class="mt-1 whitespace-pre-line">{{ $machine->memory }}</p>@else<p class="mt-1 text-muted-foreground">Nothing yet. Describe what runs on this machine so the agent knows what to expect.</p>@endif
                </div>
            </x-ui.card>

            <x-ui.card title="Client on the machine" description="Wrappers and sudo policy Sentinel relies on.">
                <div class="space-y-3 text-sm">
                    <div class="flex items-center gap-2">@if ($machine->client_checked_at)<x-ui.status-badge :status="$client->state" />@else<x-ui.badge>Not checked</x-ui.badge>@endif
                        @if ($machine->client_checked_at)<span class="text-xs text-muted-foreground">checked {{ $machine->client_checked_at->diffForHumans() }}</span>@endif</div>
                    @if ($client->state === 'updater_outdated' || $client->state === 'not_installed')
                        <p class="text-muted-foreground">The root updater on this machine is missing or older. Run the provisioning command once more (Provisioning tab).</p>
                    @endif
                </div>
                <x-slot:footer>
                    <x-ui.button size="sm" variant="outline" wire:click="checkClient" wire:loading.attr="disabled" wire:target="checkClient">@svg('lucide-refresh-cw') Check</x-ui.button>
                    @if ($client->canUpdateRemotely())
                        <x-ui.button size="sm" wire:click="updateClient" wire:loading.attr="disabled" wire:target="updateClient">@svg('lucide-cloud-download') Update over SSH</x-ui.button>
                    @endif
                </x-slot:footer>
            </x-ui.card>
        </div>

        @include('livewire.machines.partials.overview-extras')

        <x-ui.card title="Latest scans" flush>
            @include('livewire.machines.partials.runs', ['runs' => $runs->take(5)])
        </x-ui.card>
    @endif

    @if ($tab === 'provisioning')
        <div @if (! $machine->host_key_fingerprint && $machine->hasProvisionToken()) wire:poll.3s @endif class="grid gap-6">
            <x-ui.card title="1. Provision with one command" description="Run it as root on the machine. It creates the restricted user, installs the key and the sudo policy, then reports back so the host key is pinned without any copy-paste.">
                @if ($machine->hasProvisionToken())
                    <x-ui.code :text="'curl -fsSL \''.$machine->provisionUrl().'\' | sudo bash'" />
                    <div class="mt-3 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                        <span>Valid until {{ $machine->provision_token_expires_at->format('H:i') }} ({{ $machine->provision_token_expires_at->diffForHumans() }}). The machine must reach {{ config('app.url') }}.</span>
                        @if (! $machine->host_key_fingerprint)<x-ui.live label="Waiting for the machine…" />@endif
                    </div>
                @else
                    <p class="text-sm text-muted-foreground">Issue a link, valid one hour, bound to this machine. Anyone holding it can download the script, which contains no secret key.</p>
                @endif
                @can('admin')
                <x-slot:footer>
                    <x-ui.button size="sm" variant="outline" wire:click="issueLink">@svg('lucide-link') {{ $machine->hasProvisionToken() ? 'Issue a new link' : 'Issue a link' }}</x-ui.button>
                    @if ($machine->hasProvisionToken())<x-ui.button size="sm" variant="ghost" wire:click="revokeLink">Invalidate</x-ui.button>@endif
                    <x-ui.button size="sm" variant="ghost" class="ml-auto" :href="route('machines.provision-script', $machine)" :navigate="false" download>@svg('lucide-download') Download the script instead</x-ui.button>
                </x-slot:footer>
                @endcan
            </x-ui.card>

            <x-ui.card title="2. Host key" description="Sentinel refuses to connect to a server whose host key it did not pin.">
                @if ($machine->host_key_fingerprint)
                    <div class="flex items-center gap-2"><x-ui.badge variant="success" dot>Pinned</x-ui.badge><code class="break-all text-xs">{{ $machine->host_key_fingerprint }}</code></div>
                @else
                    <p class="mb-4 text-sm text-muted-foreground">Pinned automatically when the one-liner above reports back. Otherwise, compare the key the server presents with the fingerprint printed by the script (or your provider's console), and pin it.</p>
                    @if ($machine->host_keys_reported)
                        <p class="mb-1 text-xs font-medium">Reported by the script:</p>
                        <ul class="mb-4 space-y-0.5 font-mono text-xs text-muted-foreground">@foreach ($machine->host_keys_reported as $reported)<li>{{ $reported }}</li>@endforeach</ul>
                    @endif
                    @can('admin')
                    <form wire:submit="pin" class="flex flex-col gap-2 sm:flex-row">
                        <x-ui.input wire:model="fingerprint" placeholder="SHA256:…" class="font-mono" />
                        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="pin">Verify and pin</x-ui.button>
                    </form>
                    @endcan
                    @error('fingerprint')<p class="mt-2 text-xs text-destructive">{{ $message }}</p>@enderror
                @endif
                @can('admin')
                <x-slot:footer>
                    <x-ui.button size="sm" variant="outline" wire:click="showHostKey" wire:loading.attr="disabled" wire:target="showHostKey">Show the key the server presents</x-ui.button>
                    @if ($presented)<code class="break-all text-xs">{{ $presented }}</code>@endif
                </x-slot:footer>
                @endcan
            </x-ui.card>

            <x-ui.card title="Public key" description="The only part of Sentinel's SSH key that leaves Sentinel (restricted to SENTINEL_SOURCE_IPS when set).">
                <x-ui.code :text="$publicKey" />
            </x-ui.card>
        </div>
    @endif

    @if ($tab === 'chat')
        <livewire:machine-chat :machine="$machine" :key="'chat'.$machine->id" />
    @endif

    @if ($tab === 'scans')
        <x-ui.card flush>@include('livewire.machines.partials.runs', ['runs' => $runs])</x-ui.card>
    @endif

    @if ($tab === 'activity')
        {{-- Directives do not compile inside <x-…> tags: the polling attribute lives on a plain wrapper. --}}
        <div @realtime wire:poll.30s @else wire:poll.5s @endrealtime>
            <x-ui.card title="Activity on this machine" flush>
                @include('livewire.partials.activity', ['entries' => $activity])
            </x-ui.card>
        </div>
    @endif

    @include('livewire.partials.scan-modal')
</div>
