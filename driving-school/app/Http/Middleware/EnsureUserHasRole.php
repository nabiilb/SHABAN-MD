<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Route level gate: `role:admin` or `role:admin,instructor`.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            abort(403, __('Your account is not active.'));
        }

        if (! $user->hasRole(...$roles)) {
            abort(403, __('You are not authorized to access this area.'));
        }

        return $next($request);
    }
}
