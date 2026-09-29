<?php

namespace App\Http\Middleware;

use App\Domain\Dates;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every API response: the server's clock (the web app aligns its countdowns and
 * session checks with it — the server is the source of truth for deadlines),
 * no caching, and strict security headers for a JSON API.
 */
class ApiResponseHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $h = $response->headers;
        $h->set('X-Server-Time', (string) Dates::nowMs());
        if (! str_contains((string) $h->get('Cache-Control'), 'no-store')) {
            $h->set('Cache-Control', 'no-store, private');
        }
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'DENY');
        $h->set('Referrer-Policy', 'no-referrer');
        $h->set('Cross-Origin-Resource-Policy', 'same-origin');
        $h->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        if ($request->isSecure()) {
            $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
