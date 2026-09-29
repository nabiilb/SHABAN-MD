<?php

use App\Domain\ApiErrors;
use App\Exceptions\ApiException;
use App\Http\Middleware;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware as MiddlewareConfig;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (MiddlewareConfig $middleware): void {
        // Request bodies are normalised per field by the form requests (like the web app's schemas):
        // passwords are never trimmed and "" is not null.
        $middleware->remove([TrimStrings::class, ConvertEmptyStringsToNull::class]);

        // Reverse proxies whose X-Forwarded-* headers are trusted (client IP for rate limits, https detection).
        $proxies = array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))));
        if ($proxies) {
            $middleware->trustProxies(at: $proxies);
        }

        // Cookie session + CSRF token for the SPA (same Laravel machinery as the web group).
        $middleware->api(prepend: [
            Middleware\ApiResponseHeaders::class,
            Middleware\RejectMalformedJson::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            'throttle:api',
            ValidateCsrfToken::class,
            Middleware\OriginGuard::class,
        ]);
        $middleware->alias([
            'lab.auth' => Middleware\Authenticate::class,
            'permission' => Middleware\RequirePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every API error is { message, errors? } with the right status; internals are logged, never sent.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);
        $exceptions->dontReport([ApiException::class]);

        $json = fn (int $status, string $message, ?array $errors = null) => new JsonResponse($errors === null ? ['message' => $message] : ['message' => $message, 'errors' => (object) $errors], $status);

        $exceptions->render(fn (ApiException $e) => $e->render());
        $exceptions->render(fn (ValidationException $e) => $json(422, ApiErrors::VALIDATION, $e->errors()));
        $exceptions->render(fn (AuthenticationException $e) => $json(401, ApiErrors::UNAUTHENTICATED));
        $exceptions->render(fn (AuthorizationException $e) => $json(403, ApiErrors::FORBIDDEN));
        $exceptions->render(fn (AccessDeniedHttpException $e) => $json(403, ApiErrors::FORBIDDEN));
        $exceptions->render(fn (ModelNotFoundException $e) => $json(404, ApiErrors::NOT_FOUND));
        $exceptions->render(fn (NotFoundHttpException $e) => $json(404, ApiErrors::NOT_FOUND));
        $exceptions->render(fn (MethodNotAllowedHttpException $e) => $json(404, ApiErrors::NOT_FOUND));
        $exceptions->render(fn (ThrottleRequestsException $e) => $json(429, ApiErrors::RATE_LIMITED)->withHeaders($e->getHeaders()));
        // Stale or missing CSRF token: the web app fetches a fresh one (GET /api/auth/csrf) and retries once.
        $exceptions->render(fn (TokenMismatchException $e) => $json(419, 'The page expired. Please try again.'));
        $exceptions->render(fn (PostTooLargeException $e) => $json(413, 'The request is too large.'));
        // Exceptions that already carry their response (e.g. the rate limiters' JSON 429).
        $exceptions->render(fn (HttpResponseException $e) => $e->getResponse());
        $exceptions->render(function (HttpExceptionInterface $e) use ($json) {
            return match ($e->getStatusCode()) {
                401 => $json(401, ApiErrors::UNAUTHENTICATED),
                403 => $json(403, ApiErrors::FORBIDDEN),
                419 => $json(419, 'The page expired. Please try again.'),
                413 => $json(413, 'The request is too large.'),
                429 => $json(429, ApiErrors::RATE_LIMITED)->withHeaders($e->getHeaders()),
                503 => $json(503, 'The service is temporarily unavailable.'),
                default => $json($e->getStatusCode(), $e->getStatusCode() >= 500 ? ApiErrors::SERVER : ApiErrors::NOT_FOUND),
            };
        });
        $exceptions->render(fn (\Throwable $e) => config('app.debug') ? null : $json(500, ApiErrors::SERVER));
    })->create();
