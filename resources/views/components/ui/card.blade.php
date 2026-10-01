@props(['title' => null, 'description' => null, 'flush' => false])
<div {{ $attributes->class('flex flex-col rounded-xl border bg-card text-card-foreground shadow-xs') }}>
    @if ($title || isset($actions))
        <div class="flex items-start justify-between gap-4 px-6 pt-5 {{ $flush ? 'pb-4' : '' }}">
            <div class="min-w-0">
                @if ($title)<h3 class="font-semibold leading-none tracking-tight">{{ $title }}</h3>@endif
                @if ($description)<p class="mt-1.5 text-sm text-muted-foreground">{{ $description }}</p>@endif
            </div>
            @isset($actions)<div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>@endisset
        </div>
    @endif
    <div @class(['px-6 py-5' => ! $flush, 'pt-4' => $title && ! $flush, 'overflow-x-auto' => $flush])>{{ $slot }}</div>
    @isset($footer)<div class="flex items-center gap-2 border-t px-6 py-3">{{ $footer }}</div>@endisset
</div>
