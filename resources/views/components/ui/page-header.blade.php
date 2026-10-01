@props(['title', 'description' => null])
<div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <div class="min-w-0">
        <h1 class="truncate text-2xl font-semibold tracking-tight">{{ $title }}</h1>
        @if ($description)<p class="mt-1 text-sm text-muted-foreground">{{ $description }}</p>@endif
    </div>
    @isset($actions)<div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>@endisset
</div>
