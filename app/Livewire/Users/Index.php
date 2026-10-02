<?php

namespace App\Livewire\Users;

use App\Livewire\Concerns\AuthorizesAccess;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Who may do what. Accounts are created with php artisan sentinel:admin; here an administrator changes or removes them. */
#[Layout('components.layouts.app', ['title' => 'Users'])]
class Index extends Component
{
    use AuthorizesAccess;

    protected function requiredAbility(): string
    {
        return 'admin';
    }

    public function setRole(int $id, string $role): void
    {
        validator(['role' => $role], ['role' => ['present', Rule::in(['', ...array_keys(User::ROLES)])]])->validate();
        $role = $role === '' ? null : $role;
        $user = $this->other($id);

        if ($user->role === 'admin' && $role !== 'admin') {
            $this->assertAnotherAdmin($user);
        }

        $user->forceFill(['role' => $role])->save();
        activity('users')->causedBy(auth()->user())->performedOn($user)->event('role_changed')->withProperties(['role' => $role])->log("{$user->email} is now ".($role ?? 'without access'));
        $this->dispatch('toast', message: $role ? "{$user->name} is now {$role}" : "{$user->name} has no access any more", type: 'success');
    }

    public function resetTwoFactor(int $id): void
    {
        $user = $this->other($id);
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        activity('users')->causedBy(auth()->user())->performedOn($user)->event('two_factor_reset')->log("two-factor authentication reset for {$user->email}");
        $this->dispatch('toast', message: "Two-factor authentication reset for {$user->name}", description: 'They set it up again at their next login.', type: 'success');
    }

    public function remove(int $id): void
    {
        $user = $this->other($id);

        if ($user->role === 'admin') {
            $this->assertAnotherAdmin($user);
        }

        activity('users')->causedBy(auth()->user())->event('user_removed')->withProperties(['email' => $user->email, 'role' => $user->role])->log("{$user->email} removed");
        $user->delete();
        $this->dispatch('toast', message: "{$user->name} removed", type: 'success');
    }

    /** Never yourself: an administrator cannot lock themselves out by mistake. */
    private function other(int $id): User
    {
        abort_if($id === auth()->id(), 422, 'You cannot change your own account here.');

        return User::findOrFail($id);
    }

    private function assertAnotherAdmin(User $user): void
    {
        if (User::where('role', 'admin')->whereKeyNot($user->id)->doesntExist()) {
            throw ValidationException::withMessages(['role' => 'Sentinel needs at least one administrator.']);
        }
    }

    public function render()
    {
        return view('livewire.users.index', ['users' => User::orderBy('name')->get()]);
    }
}
