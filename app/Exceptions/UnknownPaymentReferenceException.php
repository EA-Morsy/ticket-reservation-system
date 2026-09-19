<?php

namespace App\Exceptions;

/**
 * Reports a verified notification referencing an unknown payment attempt.
 */
class UnknownPaymentReferenceException extends ApiException
{
    public function __construct()
    {
        parent::__construct('NOT_FOUND', 404, 'Payment reference was not found.');
    }
}
