@props(['variant' => 'default', 'size' => 'default', 'href' => null, 'type' => 'button', 'navigate' => true])
@php
    $classes = 'inline-flex shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring/60 disabled:pointer-events-none disabled:opacity-50 [&_svg]:size-4 [&_svg]:shrink-0 '
        .match ($variant) {
            'default' => 'bg-primary text-primary-foreground shadow-xs hover:bg-primary/90',
            'destructive' => 'bg-destructive text-white shadow-xs hover:bg-destructive/90',
            'outline' => 'border bg-background shadow-xs hover:bg-accent hover:text-accent-foreground',
            'secondary' => 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
            'ghost' => 'hover:bg-accent hover:text-accent-foreground',
            'link' => 'text-primary underline-offset-4 hover:underline',
        }.' '.match ($size) {
            'default' => 'h-9 px-4 py-2',
            'sm' => 'h-8 gap-1.5 px-3 text-[13px]',
            'lg' => 'h-10 px-6',
            'icon' => 'size-9',
        };
@endphp
@if ($href)
    <a href="{{ $href }}" @if ($navigate) wire:navigate @endif {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
