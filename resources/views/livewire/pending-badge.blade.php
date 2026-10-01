<span @realtime wire:poll.60s @else wire:poll.8s @endrealtime>
    @if ($count)<x-ui.badge variant="warning" class="px-1.5">{{ $count }}</x-ui.badge>@endif
</span>
