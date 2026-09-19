<?php

namespace App\Repositories;

use App\Models\Payment;
use App\Models\Reservation;
use App\Payments\PaymentIntent;

/**
 * Persists payment attempts and retrieves locked payments for webhook processing.
 */
final class PaymentRepository
{
    public function createPending(Reservation $reservation, PaymentIntent $intent): Payment
    {
        return $reservation->payments()->create([
            'provider' => (string) config('payment.driver'),
            'reference' => $intent->reference,
            'amount_minor' => $reservation->total_minor,
            'currency' => (string) config('payment.currency'),
            'status' => 'pending',
        ]);
    }

    public function findByReferenceForUpdate(string $reference): ?Payment
    {
        return Payment::query()->where('reference', $reference)->lockForUpdate()->first();
    }

    public function hasProviderTransaction(string $providerTransactionId): bool
    {
        return Payment::query()->where('provider_transaction_id', $providerTransactionId)->exists();
    }
}
