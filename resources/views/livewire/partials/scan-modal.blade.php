<x-ui.modal name="scan" title="Run an AI scan" description="The agent inspects the machine through its tool catalog. You are taken to the report, which fills in live.">
    <form wire:submit="scan" id="scan-form" class="grid gap-4">
        <x-ui.field label="Type of scan" name="profile" hint="The web server check appears when it is enabled in the machine settings. It may start crashed services and roll back a broken configuration, each through the risk gate.">
            <x-ui.select wire:model.live="profile" :options="$this->scanTypes()" />
        </x-ui.field>
        <x-ui.field label="Objective" name="objective"><x-ui.textarea wire:model="objective" rows="3" /></x-ui.field>
    </form>
    <x-slot:footer>
        <x-ui.button variant="outline" x-on:click="$dispatch('close-modal')">Cancel</x-ui.button>
        <x-ui.button type="submit" form="scan-form" wire:loading.attr="disabled">Start scan</x-ui.button>
    </x-slot:footer>
</x-ui.modal>
