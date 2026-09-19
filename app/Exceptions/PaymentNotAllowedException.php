<?php

namespace App\Exceptions;

/**
 * Rejects payment initiation for expired or completed reservations.
 */
class PaymentNotAllowedException extends ApiException
{
    public function __construct()
    {
        parent::__construct('PAYMENT_NOT_ALLOWED', 409, 'Payment is not allowed for this reservation.');
    }
}
