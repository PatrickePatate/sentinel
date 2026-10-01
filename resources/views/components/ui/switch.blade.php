{{-- Pines-style toggle: <x-ui.switch wire:model="enabled" label="Enabled" description="..." /> --}}
@props(['label', 'description' => null])
<label class="flex cursor-pointer items-start gap-3">
    <span class="relative mt-0.5 inline-flex h-5 w-9 shrink-0">
        <input type="checkbox" {{ $attributes->class('peer sr-only') }}>
        <span class="absolute inset-0 rounded-full bg-input transition-colors peer-checked:bg-primary peer-focus-visible:ring-2 peer-focus-visible:ring-ring/60"></span>
        <span class="absolute left-0.5 top-0.5 size-4 rounded-full bg-background shadow transition-transform peer-checked:translate-x-4 dark:peer-checked:bg-primary-foreground"></span>
    </span>
    <span class="grid gap-0.5">
        <span class="text-sm font-medium leading-5">{{ $label }}</span>
        @if ($description)<span class="text-xs text-muted-foreground">{{ $description }}</span>@endif
    </span>
</label>
