@props(['label', 'name' => null, 'hint' => null, 'error' => null])
@php($error ??= $name ? $errors->first($name) : null)
<div {{ $attributes->class('grid gap-2') }}>
    <label @if ($name) for="{{ $name }}" @endif class="text-sm font-medium leading-none">{{ $label }}</label>
    {{ $slot }}
    @if ($hint && ! $error)<p class="text-xs text-muted-foreground">{{ $hint }}</p>@endif
    @if ($error)<p class="text-xs text-destructive">{{ $error }}</p>@endif
</div>
