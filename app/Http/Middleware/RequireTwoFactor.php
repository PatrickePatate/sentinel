<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Sends a signed-in user without two-factor authentication to its setup before anything else. */
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('sentinel.auth.require_two_factor') && $request->user() && ! $request->user()->hasTwoFactor()) {
            return redirect()->route('two-factor.setup');
        }

        return $next($request);
    }
}
