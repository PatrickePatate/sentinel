<?php

namespace App\Livewire\Auth;

use App\Auth\Totp;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Pairs an authenticator app with the account, then shows the recovery codes once. */
#[Layout('components.layouts.guest')]
class TwoFactorSetup extends Component
{
    public string $code = '';

    /** @var list<string> Shown once, right after setup. */
    public array $recoveryCodes = [];

    public function mount(Totp $totp)
    {
        if (auth()->user()->hasTwoFactor()) {
            return $this->redirectRoute('dashboard', navigate: true);
        }

        // Kept in the session until confirmed: an abandoned setup changes nothing on the account.
        if (! session()->has('two_factor.pending_secret')) {
            session()->put('two_factor.pending_secret', $totp->generateSecret());
        }
    }

    public function confirm(Totp $totp): void
    {
        $this->validate(['code' => ['required', 'string', 'max:10']]);
        $user = auth()->user();
        $secret = (string) session('two_factor.pending_secret');

        if ($secret === '' || ! $totp->verify($secret, $this->code, "user:{$user->id}")) {
            $this->addError('code', 'This code is not valid. Check the time on your phone and try the next one.');

            return;
        }

        $this->recoveryCodes = $totp->recoveryCodes();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => $this->recoveryCodes, 'two_factor_confirmed_at' => now()])->save();
        session()->forget('two_factor.pending_secret');
        activity('users')->performedOn($user)->causedBy($user)->event('two_factor_enabled')->log('two-factor authentication enabled');
    }

    public function render(Totp $totp)
    {
        $secret = (string) session('two_factor.pending_secret');

        return view('livewire.auth.two-factor-setup', [
            'secret' => $secret,
            'qr' => $secret !== '' ? $totp->qrSvg($totp->uri($secret, auth()->user()->email)) : null,
        ]);
    }
}
