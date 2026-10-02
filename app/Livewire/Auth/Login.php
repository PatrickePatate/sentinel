<?php

namespace App\Livewire\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('components.layouts.guest')]
class Login extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required')]
    public string $password = '';

    public bool $remember = false;

    public function login()
    {
        $this->validate();

        $throttleKey = Str::lower($this->email).'|'.request()->ip();

        // Per account and IP, plus per IP alone so one address cannot spray passwords across many accounts.
        $ipKey = 'login-ip|'.request()->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5) || RateLimiter::tooManyAttempts($ipKey, 20)) {
            $this->addError('email', 'Too many attempts. Try again in '.max(RateLimiter::availableIn($throttleKey), RateLimiter::availableIn($ipKey)).' seconds.');

            return;
        }

        $credentials = ['email' => $this->email, 'password' => $this->password];
        $user = Auth::getProvider()->retrieveByCredentials($credentials);

        if (! $user || ! Auth::getProvider()->validateCredentials($user, $credentials)) {
            RateLimiter::hit($throttleKey);
            RateLimiter::hit($ipKey, 600);
            $this->addError('email', 'These credentials do not match our records.');

            return;
        }

        RateLimiter::clear($throttleKey);
        session()->regenerate();

        // The password alone is not enough: the session only remembers who is half way through, for five minutes.
        if ($user instanceof User && $user->hasTwoFactor()) {
            session()->put('login.two_factor', ['id' => $user->id, 'remember' => $this->remember, 'expires' => now()->addMinutes(5)->timestamp]);

            return $this->redirectRoute('two-factor.challenge', navigate: true);
        }

        Auth::login($user, $this->remember);

        return $this->redirectIntended(route('dashboard'), navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
