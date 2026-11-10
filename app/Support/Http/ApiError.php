<?php

namespace App\Support\Http;

use Exception;

/**
 * A business failure the API reports to the client.
 *
 * `errorCode` is the stable, machine-readable value from the table in docs/openapi.yaml.
 * Clients switch on it. Throw one of these anywhere and ProblemRenderer turns it into
 * the contract's error response.
 */
final class ApiError extends Exception
{
    /**
     * @param  array<string, list<string>>|null  $fieldErrors
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        public readonly string $title,
        public readonly ?array $fieldErrors = null,
    ) {
        parent::__construct($title);
    }

    public static function unauthorized(string $code, string $title): self
    {
        return new self(401, $code, $title);
    }

    public static function forbidden(string $code, string $title): self
    {
        return new self(403, $code, $title);
    }

    public static function notFound(string $title = 'Not found'): self
    {
        return new self(404, 'NOT_FOUND', $title);
    }

    public static function conflict(string $code, string $title): self
    {
        return new self(409, $code, $title);
    }
}
