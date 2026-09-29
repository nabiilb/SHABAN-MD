<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A JSON body that does not parse is a 400, not silently treated as empty input. */
class RejectMalformedJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $content = $request->getContent();
        if ($content !== '' && $request->isJson()) {
            json_decode($content);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new JsonResponse(['message' => 'The request body is not valid JSON.'], 400);
            }
        }

        return $next($request);
    }
}
