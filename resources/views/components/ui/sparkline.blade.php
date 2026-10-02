{{-- A small single-series line (no axes): the label next to it names the series. Hovering shows a crosshair and the value of the nearest sample. --}}
@props(['points', 'unit' => '', 'max' => null, 'width' => 160, 'height' => 32])
@php
    $values = array_column($points, 'value');
    $top = $max ?? max(max($values ?: [1]), 0.0001);
    $count = count($points);
    $xy = [];
    foreach ($points as $i => $point) {
        $xy[] = [round($count > 1 ? $i * ($width - 4) / ($count - 1) + 2 : $width / 2, 1), round($height - 2 - min($point['value'] / $top, 1) * ($height - 4), 1), $point];
    }
    $step = $count > 1 ? ($width - 4) / ($count - 1) : $width;
@endphp
<svg {{ $attributes->class('overflow-visible text-primary') }} height="{{ $height }}" viewBox="0 0 {{ $width }} {{ $height }}" preserveAspectRatio="none" role="img" aria-label="{{ $count }} samples" x-data="{ hover: null }" x-on:mouseleave="hover = null">
    <line x1="0" x2="{{ $width }}" y1="{{ $height - 2 }}" y2="{{ $height - 2 }}" class="stroke-border" stroke-width="1" vector-effect="non-scaling-stroke" />
    @if ($count > 1)
        <polyline points="{{ collect($xy)->map(fn ($p) => $p[0].','.$p[1])->implode(' ') }}" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
    @endif
    @foreach ($xy as $i => [$x, $y, $point])
        <line x1="{{ $x }}" x2="{{ $x }}" y1="0" y2="{{ $height }}" class="stroke-muted-foreground" stroke-width="1" vector-effect="non-scaling-stroke" x-show="hover === {{ $i }}" x-cloak />
        <rect x="{{ $x - $step / 2 }}" y="0" width="{{ max($step, 8) }}" height="{{ $height }}" fill="transparent" x-on:mouseenter="hover = {{ $i }}"><title>{{ $point['at']->format('M d H:i') }}: {{ rtrim(rtrim(number_format($point['value'], 2), '0'), '.') }}{{ $unit }}</title></rect>
    @endforeach
</svg>
