<div class="mx-auto max-w-2xl space-y-6">
    <x-ui.page-header :title="$title" description="Sentinel generates the SSH account and key itself: you only describe where the machine is." />

    <form wire:submit="save">
        <x-ui.card>
            <div class="grid gap-5">
                <x-ui.field label="Name" name="name"><x-ui.input wire:model="name" maxlength="100" placeholder="web-01" /></x-ui.field>
                <div class="grid gap-5 sm:grid-cols-[1fr_8rem]">
                    <x-ui.field label="Host" name="host" hint="IPv4, IPv6 or hostname. Changing it unpins the host key."><x-ui.input wire:model="host" placeholder="203.0.113.7" /></x-ui.field>
                    <x-ui.field label="SSH port" name="port"><x-ui.input type="number" wire:model="port" min="1" max="65535" /></x-ui.field>
                </div>
                <x-ui.field label="Environment" name="environment"><x-ui.select wire:model="environment" :options="['production' => 'Production', 'staging' => 'Staging']" /></x-ui.field>
                <x-ui.field label="Memory" name="memory" hint="What this machine is for and what should run on it. The agent reads it in every scan and chat, e.g. to notice a missing component. Context only: it never bypasses the risk gate.">
                    <x-ui.textarea wire:model="memory" rows="5" maxlength="3000" placeholder="Web server for shop.example.org: nginx, php8.3-fpm, mysql and redis. Deploys are done from /var/www/shop. Backups run at 03:00, so high load then is normal." />
                </x-ui.field>
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-ui.field label="Autonomous audit" name="scan_interval_minutes" hint="Security and health. Needs the scheduler and a queue worker.">
                        <x-ui.select wire:model="scan_interval_minutes" :options="$profiles['audit']['frequencies']" />
                    </x-ui.field>

                </div>
                <div class="grid gap-4 rounded-md border p-4">
                    <x-ui.switch wire:model.live="webserver_enabled" label="Web server analysis" description="Opt-in. Lets Sentinel check the web stack of this machine (web server, php-fpm, database, cache, queues), and start crashed services or roll back a broken configuration through the risk gate. Off: nothing of this runs, scheduled or triggered." />
                    @if ($webserver_enabled)
                        <div class="grid gap-5 sm:grid-cols-2">
                            <x-ui.field label="Quick check" name="webserver_interval_minutes" hint="Plain commands, no AI call: units, configuration tests, a local HTTP probe. The AI is only called when it finds something. Needs the scheduler and a queue worker.">
                                <x-ui.select wire:model="webserver_interval_minutes" :options="$profiles['webserver']['frequencies']" />
                            </x-ui.field>
                            <x-ui.field label="AI check even when healthy" name="webserver_full_check_hours" hint="The AI also reads logs and compares with the machine memory. Each one costs model calls.">
                                <x-ui.select wire:model="webserver_full_check_hours" :options="$fullCheckChoices" />
                            </x-ui.field>
                        </div>
                    @endif
                </div>
                <x-ui.switch wire:model="autonomy_enabled" label="Let the agent run low-risk corrective actions on its own" description="Only after a second classifier model agrees. High risk is never run." />
                @if ($autonomy_enabled)<x-ui.switch wire:model="autonomy_medium" label="Also allow moderate-risk actions" description="Moderate actions (e.g. restarting a service) go through the same classifier checks instead of always waiting for you. Off by default." class="ml-12" />@endif
                <x-ui.field label="Monthly model budget (USD)" name="monthly_budget_usd" hint="Optional. Past it, routine scheduled AI scans of this machine pause until next month; manual scans and checks of detected problems still run. See the Costs page.">
                    <x-ui.input wire:model="monthly_budget_usd" inputmode="decimal" placeholder="No limit" class="w-40" />
                </x-ui.field>
                <x-ui.switch wire:model="two_person_approval" label="Require two people to approve actions" description="An action held for approval runs only once two different dashboard users approved it (Telegram and command line approvals are refused on this machine). Autonomous low-risk actions are not affected." />
                <details class="rounded-md border p-4" @if ($maintenance_days) open @endif>
                    <summary class="cursor-pointer text-sm font-medium">Maintenance window</summary>
                    <p class="mt-2 text-xs text-muted-foreground">A weekly slot ({{ config('app.timezone') }}) when approved actions may run: "Approve for the window" on a pending action runs it at the next one. Scan and machine alerts are muted during the window (approvals and failed action checks are not). No day picked: no window.</p>
                    <div class="mt-4 flex flex-wrap gap-3">
                        @foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $day => $label)
                            <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" class="size-4 rounded border-input" wire:model="maintenance_days" value="{{ $day }}"> {{ $label }}</label>
                        @endforeach
                    </div>
                    @error('maintenance_days.*')<p class="mt-1 text-xs text-destructive">{{ $message }}</p>@enderror
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <x-ui.field label="Starts at" name="maintenance_start"><x-ui.input type="time" wire:model="maintenance_start" /></x-ui.field>
                        <x-ui.field label="Lasts" name="maintenance_minutes"><x-ui.select wire:model="maintenance_minutes" :options="\App\Livewire\Machines\Form::WINDOW_LENGTHS" /></x-ui.field>
                    </div>
                </details>
                <details class="rounded-md border p-4" @if (filled($gate_max_destructive) || filled($gate_min_reversible) || filled($gate_max_actions)) open @endif>
                    <summary class="cursor-pointer text-sm font-medium">Risk gate tuning for this machine</summary>
                    <p class="mt-2 text-xs text-muted-foreground">Leave empty to use the global defaults ({{ config('sentinel.gate.max_destructive') }} / {{ config('sentinel.gate.min_reversible') }} / {{ config('sentinel.gate.max_autonomous_actions_per_run') }}). Lower "destructive" and higher "reversible" make the gate stricter. The limits are bounded: this cannot switch the gate off.</p>
                    <div class="mt-4 grid gap-4 sm:grid-cols-3">
                        <x-ui.field label="Max destructive" name="gate_max_destructive" hint="0 to 0.2"><x-ui.input wire:model="gate_max_destructive" inputmode="decimal" placeholder="0.05" /></x-ui.field>
                        <x-ui.field label="Min reversible" name="gate_min_reversible" hint="0.5 to 1"><x-ui.input wire:model="gate_min_reversible" inputmode="decimal" placeholder="0.8" /></x-ui.field>
                        <x-ui.field label="Actions per scan" name="gate_max_actions" hint="0 to 10"><x-ui.input wire:model="gate_max_actions" inputmode="numeric" placeholder="3" /></x-ui.field>
                    </div>
                </details>
            </div>
            <x-slot:footer>
                <x-ui.button type="submit" wire:loading.attr="disabled">Save</x-ui.button>
                <x-ui.button variant="ghost" :href="$machineId ? route('machines.show', $machineId) : route('machines.index')">Cancel</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    </form>
</div>
