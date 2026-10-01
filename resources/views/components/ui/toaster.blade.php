<div x-data class="pointer-events-none fixed bottom-4 right-4 z-[60] flex w-80 flex-col gap-2">
    <template x-for="toast in $store.toasts.items" :key="toast.id">
        <div x-transition class="pointer-events-auto flex items-start gap-3 rounded-lg border bg-popover p-4 text-sm text-popover-foreground shadow-lg">
            <span class="mt-0.5 size-2 shrink-0 rounded-full" :class="{ 'bg-success': toast.type === 'success', 'bg-destructive': toast.type === 'error', 'bg-warning': toast.type === 'warning', 'bg-info': toast.type === 'default' }"></span>
            <div class="grid flex-1 gap-0.5"><p class="font-medium" x-text="toast.message"></p><p class="text-xs text-muted-foreground" x-show="toast.description" x-text="toast.description"></p></div>
            <button type="button" x-on:click="$store.toasts.dismiss(toast.id)" class="opacity-50 hover:opacity-100" aria-label="Dismiss">@svg('lucide-x', 'size-4')</button>
        </div>
    </template>
</div>
