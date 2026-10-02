<x-ui.card>
    <form wire:submit="verify" class="grid gap-4">
        <p class="text-sm text-muted-foreground">{{ $recovery ? 'Enter one of the recovery codes you saved when you set up two-factor authentication. Each works once.' : 'Enter the 6-digit code from your authenticator app.' }}</p>
        <x-ui.field :label="$recovery ? 'Recovery code' : 'Code'" name="code">
            <x-ui.input wire:model="code" :inputmode="$recovery ? 'text' : 'numeric'" autocomplete="one-time-code" autofocus required />
        </x-ui.field>
        <x-ui.button type="submit" class="w-full" wire:loading.attr="disabled">Verify</x-ui.button>
        <button type="button" class="text-xs text-muted-foreground underline" wire:click="$toggle('recovery')">{{ $recovery ? 'Use the authenticator app instead' : 'Lost your phone? Use a recovery code' }}</button>
    </form>
</x-ui.card>
