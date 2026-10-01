{{-- Pines-style modal. Open with $dispatch('open-modal', 'name') or x-on:click="$dispatch(...)"; close with Esc / backdrop / $dispatch('close-modal'). --}}
@props(['name', 'title', 'description' => null, 'width' => 'max-w-lg'])
<div x-data="{ open: false }" x-on:open-modal.window="if ($event.detail === '{{ $name }}') open = true" x-on:close-modal.window="open = false"
     x-on:keydown.escape.window="open = false" x-cloak x-show="open" class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
    <div x-show="open" x-transition.opacity class="absolute inset-0 bg-black/50" x-on:click="open = false"></div>
    <div x-show="open" x-trap.noscroll="open" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="scale-95 opacity-0" x-transition:enter-end="scale-100 opacity-100"
         class="relative grid max-h-[90vh] w-full {{ $width }} gap-4 overflow-y-auto rounded-xl border bg-popover p-6 text-popover-foreground shadow-lg">
        <div class="grid gap-1.5">
            <h2 class="text-lg font-semibold leading-none">{{ $title }}</h2>
            @if ($description)<p class="text-sm text-muted-foreground">{{ $description }}</p>@endif
        </div>
        {{ $slot }}
        @isset($footer)<div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">{{ $footer }}</div>@endisset
        <button type="button" x-on:click="open = false" class="absolute right-4 top-4 rounded-sm opacity-60 hover:opacity-100" aria-label="Close">@svg('lucide-x', 'size-4')</button>
    </div>
</div>
