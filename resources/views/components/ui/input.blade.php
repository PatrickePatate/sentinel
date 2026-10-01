@props(['type' => 'text'])
<input type="{{ $type }}" id="{{ $attributes->get('name') ?? $attributes->whereStartsWith('wire:model')->first() }}"
    {{ $attributes->class('flex h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs outline-none transition-colors placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring/60 disabled:opacity-50 aria-invalid:border-destructive') }}>
