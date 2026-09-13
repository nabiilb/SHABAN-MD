<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStudentProfile
{
    public function handle(Request $request, Closure $next): Response
    {
        $student = $request->user()?->student;

        if (! $student) {
            abort(403, __('No student profile is linked to your account.'));
        }

        $request->attributes->set('student', $student);
        app()->instance('current.student', $student);

        return $next($request);
    }
}
