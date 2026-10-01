@props(['destructive' => false, 'href' => null])
@if ($href)
    <a href="{{ $href }}" wire:navigate {{ $attributes->class('flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-sm hover:bg-accent [&_svg]:size-4') }}>{{ $slot }}</a>
@else
    <button type="button" {{ $attributes->class(['flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm hover:bg-accent [&_svg]:size-4', 'text-destructive' => $destructive]) }}>{{ $slot }}</button>
@endif
