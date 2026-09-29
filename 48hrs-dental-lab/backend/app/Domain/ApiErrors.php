<?php

namespace App\Domain;

/** Messages of the API error contract: every non-2xx body is { message, errors? }. */
final class ApiErrors
{
    public const UNAUTHENTICATED = 'Please sign in to continue.';

    public const SESSION_EXPIRED = 'Your session has expired. Please sign in again.';

    public const FORBIDDEN = 'Access restricted.';

    public const NOT_FOUND = 'Resource not found.';

    public const INVALID_TRANSITION = 'Invalid workflow transition.';

    public const VALIDATION = 'Some fields need attention.';

    public const RATE_LIMITED = 'Too many attempts. Wait a minute and try again.';

    public const SERVER = 'Unexpected server error.';

    public static function all(): array
    {
        return [
            'unauthenticated' => self::UNAUTHENTICATED,
            'sessionExpired' => self::SESSION_EXPIRED,
            'forbidden' => self::FORBIDDEN,
            'notFound' => self::NOT_FOUND,
            'invalidTransition' => self::INVALID_TRANSITION,
            'validation' => self::VALIDATION,
            'rateLimited' => self::RATE_LIMITED,
            'server' => self::SERVER,
        ];
    }
}
