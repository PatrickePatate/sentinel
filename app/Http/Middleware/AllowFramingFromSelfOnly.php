<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The chat is meant to live in an iframe of the Sharp back-office (same origin),
 * and nowhere else: this prevents clickjacking from other sites.
 */
class AllowFramingFromSelfOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'");
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        return $response;
    }
}
