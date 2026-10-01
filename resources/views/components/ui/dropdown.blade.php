{{-- Pines-style dropdown. Items: <x-ui.dropdown-item wire:click="..."> --}}
@props(['align' => 'right'])
<div x-data="{ open: false }" x-on:keydown.escape="open = false" x-on:click.outside="open = false" class="relative inline-block">
    <div x-on:click="open = ! open">{{ $trigger }}</div>
    <div x-cloak x-show="open" x-transition.origin.top x-on:click="open = false"
         class="absolute z-40 mt-1 min-w-48 rounded-md border bg-popover p-1 text-popover-foreground shadow-md {{ $align === 'right' ? 'right-0' : 'left-0' }}">
        {{ $slot }}
    </div>
</div>
