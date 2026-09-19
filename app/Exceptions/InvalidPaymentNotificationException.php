<?php

namespace App\Exceptions;

/**
 * Rejects a provider notification that could not be verified.
 */
class InvalidPaymentNotificationException extends ApiException
{
    public function __construct()
    {
        parent::__construct('FORBIDDEN', 403, 'Invalid payment notification.');
    }
}
