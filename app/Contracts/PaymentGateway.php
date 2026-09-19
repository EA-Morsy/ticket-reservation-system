<?php

namespace App\Contracts;

use App\Models\Reservation;
use App\Payments\PaymentIntent;
use App\Payments\PaymentNotification;

/**
 * Boundary for hosted checkout and notification verification across payment providers.
 */
interface PaymentGateway
{
    public function initiate(Reservation $reservation): PaymentIntent;

    /** @param array<string, mixed> $payload */
    public function verifyNotification(array $payload): ?PaymentNotification;
}
