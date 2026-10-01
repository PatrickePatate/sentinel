<x-ui.modal name="scan" title="Run an AI scan" description="The agent inspects the machine through its tool catalog. You are taken to the report, which fills in live.">
    <form wire:submit="scan" id="scan-form" class="grid gap-4">
        <x-ui.field label="Type of scan" name="profile" hint="The web server check appears when it is enabled in the machine settings. It may start crashed services and roll back a broken configuration, each through the risk gate.">
            <x-ui.select wire:model.live="profile" :options="$this->scanTypes()" />
        </x-ui.field>
        <x-ui.field label="Objective" name="objective"><x-ui.textarea wire:model="objective" rows="3" /></x-ui.field>
        @if ($this->scanTarget()?->autonomy_enabled)
            <p class="flex items-start gap-2 text-xs text-muted-foreground">@svg('lucide-wrench', 'mt-0.5 size-3.5 shrink-0') <span>Autonomous actions are on for this machine: the agent may fix {{ $this->scanTarget()->autonomy_medium ? 'low and moderate-risk' : 'low-risk' }} issues itself, each one checked by the risk gate.</span></p>
        @else
            <x-ui.switch wire:model="allowActions" label="Let the agent fix low-risk issues" description="Autonomy is off for this machine. Switch on to let the agent run low-risk corrective actions during this scan only, each one still checked by the risk gate." />
        @endif
    </form>
    <x-slot:footer>
        <x-ui.button variant="outline" x-on:click="$dispatch('close-modal')">Cancel</x-ui.button>
        <x-ui.button type="submit" form="scan-form" wire:loading.attr="disabled">Start scan</x-ui.button>
    </x-slot:footer>
</x-ui.modal>
