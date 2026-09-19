<?php

namespace App\Payments;

use Carbon\CarbonInterface;

/**
 * Verified provider outcome with an exact amount in minor units.
 */
readonly class PaymentNotification
{
    public function __construct(
        public string $reference,
        public string $providerTransactionId,
        public string $status,
        public int $amountMinor,
        public string $currency,
        public ?CarbonInterface $paidAt,
    ) {}
}
