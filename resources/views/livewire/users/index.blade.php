<div class="space-y-6">
    <x-ui.page-header title="Users" description="Who can see the dashboard and what they may do. Create an account with php artisan sentinel:admin email --role=viewer|approver|admin." />

    @error('role')<p class="text-sm text-destructive">{{ $message }}</p>@enderror

    <x-ui.card flush>
        <x-ui.table>
            <thead><tr><th>User</th><th>Role</th><th>Two-factor</th><th></th></tr></thead>
            <tbody>
                @foreach ($users as $user)
                    <tr wire:key="u{{ $user->id }}">
                        <td><span class="font-medium">{{ $user->name }}</span> <span class="block text-xs text-muted-foreground">{{ $user->email }}</span></td>
                        <td>
                            @if ($user->is(auth()->user()))
                                <x-ui.badge variant="outline">{{ $user->role }} (you)</x-ui.badge>
                            @else
                                <select class="h-9 w-40 rounded-md border border-input bg-transparent px-3 text-sm [&>option]:bg-popover" aria-label="Role of {{ $user->name }}" x-on:change="$wire.setRole({{ $user->id }}, $event.target.value)">
                                    <option value="" @selected($user->role === null)>No access</option>
                                    @foreach (array_keys(\App\Models\User::ROLES) as $role)<option value="{{ $role }}" @selected($user->role === $role)>{{ ucfirst($role) }}</option>@endforeach
                                </select>
                            @endif
                        </td>
                        <td>@if ($user->hasTwoFactor())<x-ui.badge variant="success">On</x-ui.badge>@else<x-ui.badge variant="warning">Not set up</x-ui.badge>@endif</td>
                        <td class="space-x-1 text-right">
                            @unless ($user->is(auth()->user()))
                                @if ($user->hasTwoFactor())
                                    <x-ui.button size="sm" variant="outline" :x-on:click="'$store.confirm.ask({ title: \'Reset two-factor authentication?\', message: '.\Illuminate\Support\Js::from($user->email.' will have to set it up again at their next login (e.g. a lost phone).').', label: \'Reset\', action: () => $wire.resetTwoFactor('.$user->id.') })'">Reset 2FA</x-ui.button>
                                @endif
                                <x-ui.button size="sm" variant="ghost" :x-on:click="'$store.confirm.ask({ title: \'Remove this user?\', message: '.\Illuminate\Support\Js::from($user->email).', label: \'Remove\', destructive: true, action: () => $wire.remove('.$user->id.') })'">@svg('lucide-trash-2')</x-ui.button>
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>
    </x-ui.card>

    <x-ui.card title="Roles">
        <dl class="space-y-2 text-sm">
            @foreach (\App\Models\User::ROLES as $role => $description)
                <div><dt class="inline font-medium capitalize">{{ $role }}</dt> <dd class="inline text-muted-foreground">{{ \Illuminate\Support\Str::after($description, ': ') }}</dd></div>
            @endforeach
        </dl>
    </x-ui.card>
</div>
