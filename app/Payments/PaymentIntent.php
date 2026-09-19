<?php

namespace App\Payments;

/**
 * Provider-independent checkout reference and URL returned when payment starts.
 */
readonly class PaymentIntent
{
    public function __construct(
        public string $reference,
        public string $hostedCheckoutUrl,
    ) {}
}
