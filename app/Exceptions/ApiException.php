<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * Carries a public error code, HTTP status, and details for centralized API rendering.
 */
abstract class ApiException extends RuntimeException implements ShouldntReport
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly string $apiCode,
        public readonly int $status,
        string $message,
        public readonly array $details = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
