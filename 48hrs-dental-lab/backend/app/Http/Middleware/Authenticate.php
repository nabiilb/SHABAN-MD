<?php

namespace App\Http\Middleware;

use App\Domain\ApiErrors;
use App\Exceptions\ApiException;
use App\Support\AuthSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires a signed-in, still-valid session. The session has an absolute end
 * (SESSION_TTL_MINUTES from sign-in; activity never extends it), and a disabled
 * account or deleted session ends it at once. A 401 tells the web app to sign in
 * again ("session expired" when there was a session that ended).
 */
class Authenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();
        if (! $user) {
            throw ApiException::unauthorized($request->session()->has(AuthSession::EXPIRES_KEY) ? ApiErrors::SESSION_EXPIRED : ApiErrors::UNAUTHENTICATED);
        }
        if (! $user->active || AuthSession::expired($request)) {
            AuthSession::end($request, keepExpiredMarker: true);
            throw ApiException::unauthorized(ApiErrors::SESSION_EXPIRED);
        }

        return $next($request);
    }
}
