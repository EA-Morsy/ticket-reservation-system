<?php

namespace App\Exceptions;

use Throwable;

/**
 * Exposes a safe checkout failure message while retaining the underlying exception.
 */
class PaymentGatewayUnavailableException extends ApiException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('PAYMENT_UNAVAILABLE', 502, 'Payment could not be initiated.', previous: $previous);
    }
}
