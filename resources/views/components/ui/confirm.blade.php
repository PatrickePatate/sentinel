{{-- Global confirmation dialog, driven by $store.confirm.ask({...}). Mounted once in the layout. --}}
<div x-data x-cloak x-show="$store.confirm.open" x-on:keydown.escape.window="$store.confirm.open = false" class="fixed inset-0 z-[70] flex items-center justify-center p-4" role="alertdialog" aria-modal="true">
    <div class="absolute inset-0 bg-black/50" x-on:click="$store.confirm.open = false"></div>
    <div x-trap.noscroll="$store.confirm.open" class="relative grid w-full max-w-md gap-4 rounded-xl border bg-popover p-6 shadow-lg">
        <div class="grid gap-1.5"><h2 class="text-lg font-semibold leading-none" x-text="$store.confirm.title"></h2><p class="text-sm text-muted-foreground" x-text="$store.confirm.message"></p></div>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <x-ui.button variant="outline" x-on:click="$store.confirm.open = false">Cancel</x-ui.button>
            <x-ui.button x-bind:class="$store.confirm.destructive && 'bg-destructive text-white hover:bg-destructive/90'" x-on:click="$store.confirm.accept()" x-text="$store.confirm.label"></x-ui.button>
        </div>
    </div>
</div>
