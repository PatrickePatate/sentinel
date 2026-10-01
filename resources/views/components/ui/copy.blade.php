{{-- Copy-to-clipboard button: <x-ui.copy :text="$command" /> --}}
@props(['text', 'label' => 'Copy'])
<button type="button" x-data="copy(@js($text))" x-on:click="copy()" {{ $attributes->class('inline-flex h-8 items-center gap-1.5 rounded-md border bg-background px-2.5 text-xs font-medium shadow-xs hover:bg-accent') }}>
    <span x-show="! done" class="inline-flex items-center gap-1.5">@svg('lucide-copy', 'size-3.5') {{ $label }}</span>
    <span x-cloak x-show="done" class="inline-flex items-center gap-1.5 text-success">@svg('lucide-check', 'size-3.5') Copied</span>
</button>
