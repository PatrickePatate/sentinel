<div class="mx-auto max-w-2xl space-y-6">
    <x-ui.page-header :title="$channelId ? 'Edit channel' : 'New channel'" />
    <form wire:submit="save">
        <x-ui.card>
            <div class="grid gap-5">
                <x-ui.field label="Name" name="name"><x-ui.input wire:model="name" maxlength="100" /></x-ui.field>
                <x-ui.field label="Type" name="type"><x-ui.select wire:model.live="type" :options="$types" /></x-ui.field>

                @if ($type === 'mail')
                    <x-ui.field label="Email address" name="email"><x-ui.input type="email" wire:model="email" maxlength="255" /></x-ui.field>
                @else
                    <x-ui.field label="Bot token" name="bot_token" hint="Write-only (from @BotFather). Leave empty to keep the current one.">
                        <x-ui.input type="password" wire:model="bot_token" autocomplete="off" :placeholder="$hasToken ? '•••••• (kept)' : ''" />
                    </x-ui.field>
                    <x-ui.field label="Chat id" name="chat_id"><x-ui.input wire:model="chat_id" maxlength="32" /></x-ui.field>
                    <x-ui.field label="Telegram user ids allowed to approve" name="approver_ids" hint="Comma separated. Anyone else pressing a button is refused and audited. Empty = the chat itself (private chats only)."><x-ui.input wire:model="approver_ids" maxlength="255" /></x-ui.field>
                @endif

                <x-ui.field label="Notify about scans from severity" name="min_severity"><x-ui.select wire:model="min_severity" :options="$severities" /></x-ui.field>
                <x-ui.switch wire:model="notify_scans" label="Scan results" description="Suspicious findings and failed scans." />
                <x-ui.switch wire:model="notify_approvals" label="Actions waiting for approval" />
                <x-ui.switch wire:model="enabled" label="Enabled" />
            </div>
            <x-slot:footer>
                <x-ui.button type="submit" wire:loading.attr="disabled">Save</x-ui.button>
                <x-ui.button variant="ghost" :href="route('channels.index')">Cancel</x-ui.button>
            </x-slot:footer>
        </x-ui.card>
    </form>
</div>
