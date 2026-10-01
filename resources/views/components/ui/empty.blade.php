@props(['icon' => 'lucide-inbox', 'title', 'description' => null])
<div {{ $attributes->class('flex flex-col items-center gap-2 px-6 py-12 text-center') }}>
    <div class="flex size-10 items-center justify-center rounded-full bg-muted text-muted-foreground">@svg($icon, 'size-5')</div>
    <p class="font-medium">{{ $title }}</p>
    @if ($description)<p class="max-w-sm text-sm text-muted-foreground">{{ $description }}</p>@endif
    {{ $slot }}
</div>
