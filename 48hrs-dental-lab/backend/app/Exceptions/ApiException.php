<?php

namespace App\Exceptions;

use App\Domain\ApiErrors;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * An API error with the contract body { message, errors?, ...extra }:
 * 401 / 403 / 404 / 409 (with currentStatus) / 422 (field errors) / 429.
 */
class ApiException extends RuntimeException
{
    /** @param array<string, list<string>>|null $errors */
    public function __construct(public readonly int $status, string $message, public readonly ?array $errors = null, public readonly array $extra = [])
    {
        parent::__construct($message);
    }

    public static function unauthorized(string $message = ApiErrors::UNAUTHENTICATED): self
    {
        return new self(401, $message);
    }

    public static function forbidden(): self
    {
        return new self(403, ApiErrors::FORBIDDEN);
    }

    public static function notFound(): self
    {
        return new self(404, ApiErrors::NOT_FOUND);
    }

    public static function tooManyRequests(): self
    {
        return new self(429, ApiErrors::RATE_LIMITED);
    }

    /** 422 with per-field messages. */
    public static function validation(array $errors, string $message = ApiErrors::VALIDATION): self
    {
        return new self(422, $message, $errors);
    }

    /** 422 for a business rule not tied to one field (errors: {}). */
    public static function unprocessable(string $message): self
    {
        return new self(422, $message, []);
    }

    public static function throwIf(array $errors): void
    {
        if ($errors) {
            throw self::validation($errors);
        }
    }

    public function render(): JsonResponse
    {
        $body = ['message' => $this->getMessage()];
        if ($this->errors !== null) {
            $body['errors'] = (object) $this->errors;
        }

        return new JsonResponse([...$body, ...$this->extra], $this->status);
    }
}
