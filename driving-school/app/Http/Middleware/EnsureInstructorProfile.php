<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarantees every instructor route has a real instructor row behind the
 * authenticated user, and shares it so controllers never read an
 * instructor id out of the request.
 */
class EnsureInstructorProfile
{
    public function handle(Request $request, Closure $next): Response
    {
        $instructor = $request->user()?->instructor;

        if (! $instructor) {
            abort(403, __('No instructor profile is linked to your account.'));
        }

        if ($instructor->status !== 'active') {
            abort(403, __('Your instructor profile is not active.'));
        }

        $request->attributes->set('instructor', $instructor);
        app()->instance('current.instructor', $instructor);

        return $next($request);
    }
}
