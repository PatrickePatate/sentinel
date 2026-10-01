@props(['options' => []])
<select id="{{ $attributes->whereStartsWith('wire:model')->first() }}" {{ $attributes->class('h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs outline-none focus-visible:ring-2 focus-visible:ring-ring/60 disabled:opacity-50 [&>option]:bg-popover') }}>
    @foreach ($options as $value => $label)
        <option value="{{ $value }}">{{ $label }}</option>
    @endforeach
    {{ $slot }}
</select>
