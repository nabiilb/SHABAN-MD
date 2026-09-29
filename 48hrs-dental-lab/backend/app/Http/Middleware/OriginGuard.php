<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defence in depth on top of the CSRF token and SameSite cookies: a state-changing
 * request that carries an Origin must come from this site or an allowed origin.
 */
class OriginGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $origin = $request->headers->get('Origin');
            if ($origin !== null && $origin !== '' && ! self::allowed($origin, $request)) {
                throw ApiException::forbidden();
            }
        }

        return $next($request);
    }

    public static function allowed(string $origin, Request $request): bool
    {
        if (in_array($origin, config('lab.allowed_origins'), true)) {
            return true;
        }
        $host = parse_url($origin, PHP_URL_HOST);
        $port = parse_url($origin, PHP_URL_PORT);

        return $host !== null && strtolower($host.($port ? ":{$port}" : '')) === strtolower($request->getHttpHost());
    }
}
