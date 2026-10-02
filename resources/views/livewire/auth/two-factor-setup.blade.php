<x-ui.card>
    @if ($recoveryCodes)
        <div class="grid gap-4">
            <p class="text-sm font-medium">Two-factor authentication is on.</p>
            <p class="text-sm text-muted-foreground">Save these recovery codes somewhere safe (a password manager). Each one lets you in once if you lose your phone. They are not shown again.</p>
            <ul class="grid grid-cols-2 gap-1 rounded-md bg-muted p-3 font-mono text-sm">@foreach ($recoveryCodes as $code)<li>{{ $code }}</li>@endforeach</ul>
            <x-ui.button :href="route('dashboard')" class="w-full">Continue to Sentinel</x-ui.button>
        </div>
    @else
        <form wire:submit="confirm" class="grid gap-4">
            <p class="text-sm text-muted-foreground">Sentinel can approve root actions on your servers, so a password is not enough. Scan this code with an authenticator app (1Password, Aegis, Google Authenticator…), then enter the code it shows.</p>
            <div class="mx-auto rounded-md bg-white p-2">{!! $qr !!}</div>
            <p class="text-center text-xs text-muted-foreground">Or enter this key by hand: <code class="select-all break-all">{{ $secret }}</code></p>
            <x-ui.field label="Code" name="code"><x-ui.input wire:model="code" inputmode="numeric" autocomplete="one-time-code" autofocus required /></x-ui.field>
            <x-ui.button type="submit" class="w-full" wire:loading.attr="disabled">Turn on</x-ui.button>
        </form>
    @endif
</x-ui.card>
