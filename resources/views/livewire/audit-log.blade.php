<div @if ($live) @realtime wire:poll.30s @else wire:poll.5s @endrealtime @endif class="space-y-6">
    <x-ui.page-header title="Audit log" description="Every command, its output and every decision.">
        <x-slot:actions>
            <button type="button" wire:click="$toggle('live')"><x-ui.live :active="$live" :label="$live ? 'Live' : 'Paused'" /></button>
        </x-slot:actions>
    </x-ui.page-header>
    <x-ui.input type="search" wire:model.live.debounce.300ms="search" placeholder="Filter by event or tool…" class="max-w-xs" />
    <x-ui.card flush>@include('livewire.partials.activity', ['entries' => $entries])</x-ui.card>
    {{ $entries->links() }}
</div>
