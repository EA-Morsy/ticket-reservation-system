<?php

namespace App\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Simulates hosted checkout and signed notifications without external payments.
 */
class FakePaymentGateway implements PaymentGateway
{
    public function initiate(Reservation $reservation): PaymentIntent
    {
        $reference = 'fake_'.Str::lower((string) Str::ulid());

        return new PaymentIntent($reference, "https://fake-payments.test/checkout/{$reference}");
    }

    /** @param array<string, mixed> $payload */
    public function verifyNotification(array $payload): ?PaymentNotification
    {
        $signature = Arr::pull($payload, 'signature');

        if (! is_string($signature) || ! hash_equals($this->signature($payload), $signature)) {
            return null;
        }

        $reference = Arr::get($payload, 'reference');
        $providerTransactionId = Arr::get($payload, 'provider_transaction_id');
        $status = Arr::get($payload, 'status');
        $amountMinor = Arr::get($payload, 'amount_minor');
        $currency = Arr::get($payload, 'currency');

        if (! is_string($reference) || ! is_string($providerTransactionId) || ! in_array($status, ['success', 'failed'], true)
            || ! is_int($amountMinor) || ! is_string($currency)) {
            return null;
        }

        $paidAt = Arr::get($payload, 'paid_at');

        return new PaymentNotification(
            $reference,
            $providerTransactionId,
            $status,
            $amountMinor,
            $currency,
            is_string($paidAt) ? Carbon::parse($paidAt) : null,
        );
    }

    /** @return array<string, mixed> */
    public function notificationPayload(string $reference, string $status, int $amountMinor, string $currency): array
    {
        $payload = [
            'reference' => $reference,
            'provider_transaction_id' => 'fake_txn_'.Str::lower((string) Str::ulid()),
            'status' => $status,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'paid_at' => now()->toIso8601String(),
        ];

        $payload['signature'] = $this->signature($payload);

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function signature(array $payload): string
    {
        ksort($payload);

        return hash_hmac('sha512', json_encode($payload, JSON_THROW_ON_ERROR), (string) config('payment.fake_signature_secret'));
    }
}
