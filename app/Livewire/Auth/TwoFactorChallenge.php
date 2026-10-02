<?php

namespace App\Livewire\Auth;

use App\Auth\Totp;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Second step of the login: a code from the authenticator app, or one of the recovery codes. */
#[Layout('components.layouts.guest')]
class TwoFactorChallenge extends Component
{
    public string $code = '';

    public bool $recovery = false;

    public function mount()
    {
        if (! $this->pendingUser()) {
            return $this->redirectRoute('login', navigate: true);
        }
    }

    public function verify(Totp $totp)
    {
        $this->validate(['code' => ['required', 'string', 'max:32']]);
        $user = $this->pendingUser();

        if (! $user) {
            return $this->redirectRoute('login', navigate: true);
        }

        $key = "two-factor|{$user->id}";

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('code', 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.');

            return;
        }

        if (! ($this->recovery ? $this->useRecoveryCode($user) : $totp->verify($user->two_factor_secret, $this->code, "user:{$user->id}"))) {
            RateLimiter::hit($key, 60);
            $this->addError('code', $this->recovery ? 'This recovery code is not valid.' : 'This code is not valid.');

            return;
        }

        RateLimiter::clear($key);
        $remember = (bool) session('login.two_factor.remember');
        session()->forget('login.two_factor');
        Auth::login($user, $remember);
        session()->regenerate();

        return $this->redirectIntended(route('dashboard'), navigate: true);
    }

    /** Each recovery code works once. */
    private function useRecoveryCode(User $user): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];
        $given = strtolower(trim($this->code));

        foreach ($codes as $i => $code) {
            if (hash_equals($code, $given)) {
                unset($codes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();
                activity('users')->performedOn($user)->causedBy($user)->event('recovery_code_used')->withProperties(['left' => count($codes)])->log('recovery code used');

                return true;
            }
        }

        return false;
    }

    private function pendingUser(): ?User
    {
        $pending = session('login.two_factor');

        if (! is_array($pending) || ($pending['expires'] ?? 0) < now()->timestamp) {
            return null;
        }

        $user = User::find($pending['id'] ?? 0);

        return $user?->hasTwoFactor() ? $user : null;
    }

    public function render()
    {
        return view('livewire.auth.two-factor-challenge');
    }
}
