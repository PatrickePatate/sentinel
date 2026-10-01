@props(['label', 'value', 'icon' => null, 'hint' => null, 'tone' => null, 'href' => null])
<{{ $href ? 'a' : 'div' }} @if ($href) href="{{ $href }}" wire:navigate @endif {{ $attributes->class(['rounded-xl border bg-card p-5 shadow-xs', 'transition-colors hover:bg-accent/50' => $href]) }}>
    <div class="flex items-center justify-between text-sm text-muted-foreground">
        <span>{{ $label }}</span>
        @if ($icon)@svg($icon, 'size-4')@endif
    </div>
    <div @class(['mt-2 text-3xl font-semibold tabular-nums tracking-tight', 'text-destructive' => $tone === 'danger', 'text-warning' => $tone === 'warning', 'text-success' => $tone === 'success'])>{{ $value }}</div>
    @if ($hint)<p class="mt-1 text-xs text-muted-foreground">{{ $hint }}</p>@endif
</{{ $href ? 'a' : 'div' }}>
