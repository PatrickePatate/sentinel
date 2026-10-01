{{-- Pines-style dropdown. Items: <x-ui.dropdown-item wire:click="..."> The menu is fixed-positioned (not teleported, so wire: actions keep working) so overflow-hidden parents (tables, cards) never clip it. --}}
@props(['align' => 'right'])
<div x-data="{
        open: false, top: 0, left: 0, right: 0,
        toggle() {
            this.open = ! this.open
            if (! this.open) return
            const r = this.$refs.trigger.getBoundingClientRect()
            const below = window.innerHeight - r.bottom
            this.left = r.left
            this.right = window.innerWidth - r.right
            this.top = r.bottom + 4
            this.$nextTick(() => {
                const h = this.$refs.menu.offsetHeight
                if (this.top + h > window.innerHeight - 8 && r.top - h - 4 > 8) this.top = r.top - h - 4
            })
        },
    }"
    x-on:keydown.escape="open = false" x-on:click.outside="open = false" x-on:scroll.window="open = false" x-on:resize.window="open = false"
    class="relative inline-block">
    <div x-ref="trigger" x-on:click="toggle()">{{ $trigger }}</div>
    <div x-ref="menu" x-cloak x-show="open" x-transition.opacity x-on:click="open = false" 
             :style="`top:${top}px;` + ('{{ $align }}' === 'right' ? `right:${right}px` : `left:${left}px`)"
             class="fixed z-50 min-w-48 rounded-md border bg-popover p-1 text-popover-foreground shadow-md">
        {{ $slot }}
    </div>
</div>
