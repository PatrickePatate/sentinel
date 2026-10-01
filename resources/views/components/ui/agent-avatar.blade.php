{{-- Robot avatar with a hover/focus tooltip telling which provider and model answer. <x-ui.agent-avatar provider="anthropic" model="..." /> --}}
@props(['provider' => null, 'model' => null])
<span {{ $attributes->class('group relative inline-flex shrink-0') }} tabindex="0">
    <span class="flex size-8 items-center justify-center rounded-full bg-primary text-primary-foreground">@svg('lucide-bot', 'size-4')</span>
    <span role="tooltip" class="pointer-events-none absolute left-0 top-full z-20 mt-1.5 w-max max-w-64 translate-y-0.5 rounded-md border bg-popover px-3 py-2 text-xs text-popover-foreground opacity-0 shadow-md transition duration-150 group-hover:translate-y-0 group-hover:opacity-100 group-focus:translate-y-0 group-focus:opacity-100">
        @if ($provider === 'precheck')
            <span class="block font-medium">No AI call</span><span class="text-muted-foreground">Answered by the plain web stack check</span>
        @else
            <span class="block text-muted-foreground">Provider <span class="font-medium text-popover-foreground">{{ $provider ?: 'unknown' }}</span></span>
            <span class="block text-muted-foreground">Model <span class="font-medium text-popover-foreground">{{ $model ?: 'provider default' }}</span></span>
        @endif
    </span>
</span>
