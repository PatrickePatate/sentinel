{{-- Tab strip bound to a Livewire property (so it is deep-linkable): <x-ui.tabs model="tab" :tabs="['overview' => 'Overview']" /> --}}
@props(['model', 'tabs', 'current'])
<div {{ $attributes->class('inline-flex h-9 w-fit items-center rounded-lg bg-muted p-[3px] text-muted-foreground') }} role="tablist">
    @foreach ($tabs as $key => $label)
        <button type="button" role="tab" wire:click="$set('{{ $model }}', '{{ $key }}')" @class(['inline-flex h-[calc(100%-1px)] items-center gap-1.5 rounded-md px-3 text-sm font-medium transition-all', 'bg-background text-foreground shadow-sm' => $current === $key, 'hover:text-foreground' => $current !== $key])>{{ $label }}</button>
    @endforeach
</div>
