<?php

namespace App\Http\Middleware;

class SecurityHeaders
{
    public function handle($request, \Closure $next)
    {
        $response = $next($request);
        foreach (['X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY', 'Referrer-Policy' => 'same-origin', 'Cache-Control' => 'no-store',
            'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'"] as $key => $value) {
            $response->headers->set($key, $value);
        }

        return $response;
    }
}
