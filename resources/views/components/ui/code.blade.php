@props(['text', 'copy' => true])
<div class="relative">
    <pre {{ $attributes->class('overflow-x-auto rounded-md border bg-muted px-4 py-3 pr-24 font-mono text-[12.5px] leading-relaxed') }}><code>{{ $text }}</code></pre>
    @if ($copy)<x-ui.copy :text="$text" class="absolute right-2 top-2" />@endif
</div>
