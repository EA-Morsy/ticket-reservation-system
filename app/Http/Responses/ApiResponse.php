<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * Defines the shared JSON envelopes for successful requests and API errors.
 */
final class ApiResponse
{
    public static function success(mixed $data = null, string $message = 'Request completed successfully.', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    /** @param array<string, mixed> $details */
    public static function error(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => array_filter([
                'code' => $code,
                'details' => $details === [] ? null : $details,
            ], static fn (mixed $value): bool => $value !== null),
        ], $status);
    }

    /** @param array<string, array<int, string>> $errors */
    public static function validation(array $errors): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'The given data was invalid.',
            'error' => [
                'code' => 'VALIDATION_ERROR',
                'details' => $errors,
            ],
        ], 422);
    }
}
