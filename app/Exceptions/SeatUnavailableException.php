<?php

namespace App\Exceptions;

/**
 * Reports conflicting seat labels and reasons without revealing their owners.
 */
class SeatUnavailableException extends ApiException
{
    /** @param array<int, array{number: string, reason: string}> $seats */
    public function __construct(public readonly array $seats)
    {
        parent::__construct('SEAT_UNAVAILABLE', 409, 'One or more selected seats are unavailable.', ['seats' => $seats]);
    }
}
