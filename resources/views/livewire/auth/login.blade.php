<x-ui.card>
    <form wire:submit="login" class="grid gap-4">
        <x-ui.field label="Email" name="email">
            <x-ui.input type="email" wire:model="email" autocomplete="username" autofocus required />
        </x-ui.field>
        <x-ui.field label="Password" name="password">
            <x-ui.input type="password" wire:model="password" autocomplete="current-password" required />
        </x-ui.field>
        <x-ui.switch wire:model="remember" label="Remember me" />
        <x-ui.button type="submit" class="w-full" wire:loading.attr="disabled">Sign in</x-ui.button>
    </form>
</x-ui.card>
