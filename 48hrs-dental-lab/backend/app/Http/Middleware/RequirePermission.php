<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard — permission:a,b (all) or permission:any,a,b (any). Enforced on the
 * server whatever the web app shows; the permissions come from the database on
 * every request, so role changes apply at once.
 */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $mode = 'all';
        if (($permissions[0] ?? null) === 'any') {
            $mode = 'any';
            array_shift($permissions);
        }
        $user = $request->user();
        if (! $user) {
            throw ApiException::unauthorized();
        }
        if (! $user->hasPermission($permissions, $mode)) {
            throw ApiException::forbidden();
        }

        return $next($request);
    }
}
