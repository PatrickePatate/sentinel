@props(['variant' => 'secondary', 'dot' => false])
@php
    $classes = match ($variant) {
        'default' => 'border-transparent bg-primary text-primary-foreground',
        'secondary' => 'border-transparent bg-secondary text-secondary-foreground',
        'outline' => 'text-foreground',
        'success' => 'border-success/30 bg-success/10 text-success',
        'warning' => 'border-warning/30 bg-warning/10 text-warning',
        'destructive' => 'border-destructive/30 bg-destructive/10 text-destructive',
        'info' => 'border-info/30 bg-info/10 text-info',
    };
@endphp
<span {{ $attributes->class("inline-flex w-fit shrink-0 items-center gap-1.5 whitespace-nowrap rounded-md border px-2 py-0.5 text-xs font-medium [&_svg]:size-3 $classes") }}>
    @if ($dot)<span class="size-1.5 rounded-full bg-current"></span>@endif
    {{ $slot }}
</span>
