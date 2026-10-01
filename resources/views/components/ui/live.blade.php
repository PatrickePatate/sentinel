{{-- Pulsing "live" indicator for views that refresh themselves. --}}
@props(['label' => 'Live', 'active' => true])
<span {{ $attributes->class('inline-flex items-center gap-1.5 text-xs text-muted-foreground') }}>
    <span class="relative flex size-2">
        @if ($active)<span class="absolute inline-flex size-full animate-ping rounded-full bg-success opacity-60"></span>@endif
        <span @class(['relative inline-flex size-2 rounded-full', 'bg-success' => $active, 'bg-muted-foreground/40' => ! $active])></span>
    </span>{{ $label }}
</span>
