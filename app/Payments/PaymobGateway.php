<?php

namespace App\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Adapts Paymob hosted checkout and HMAC notifications to the shared payment contract.
 */
class PaymobGateway implements PaymentGateway
{
    /** @var array<int, string> */
    private const HMAC_FIELDS = [
        'amount_cents', 'created_at', 'currency', 'error_occured', 'has_parent_transaction',
        'id', 'integration_id', 'is_3d_secure', 'is_auth', 'is_capture', 'is_refunded',
        'is_standalone_payment', 'is_voided', 'order.id', 'owner', 'pending', 'source_data.pan',
        'source_data.sub_type', 'source_data.type', 'success',
    ];

    public function __construct(private HttpFactory $http) {}

    public function initiate(Reservation $reservation): PaymentIntent
    {
        $secretKey = (string) config('payment.providers.paymob.secret_key');
        $integrationId = (string) config('payment.providers.paymob.integration_id');
        $publicKey = (string) config('payment.providers.paymob.public_key');

        if ($secretKey === '' || $integrationId === '' || $publicKey === '') {
            throw new RuntimeException('Paymob checkout is not configured.');
        }

        $reference = 'paymob_'.Str::lower((string) Str::ulid());
        $response = $this->http
            ->baseUrl(rtrim((string) config('payment.providers.paymob.base_url'), '/'))
            ->acceptJson()
            ->withToken($secretKey, 'Token')
            ->post('/v1/intention/', [
                'amount' => $reservation->total_minor,
                'currency' => (string) config('payment.currency'),
                'payment_methods' => [(int) $integrationId],
                'items' => [[
                    'name' => "Reservation {$reservation->id}",
                    'amount' => $reservation->total_minor,
                    'description' => 'Ticket reservation',
                    'quantity' => 1,
                ]],
                'billing_data' => [
                    'apartment' => 'NA', 'first_name' => 'Ticket', 'last_name' => 'Customer',
                    'street' => 'NA', 'building' => 'NA', 'phone_number' => 'NA',
                    'city' => 'NA', 'country' => 'EG', 'email' => 'NA@example.test',
                    'floor' => 'NA', 'state' => 'NA',
                ],
                'special_reference' => $reference,
                'expiration' => 1800,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Paymob checkout could not be initiated.');
        }

        $clientSecret = $response->json('client_secret');

        if (! is_string($clientSecret) || $clientSecret === '') {
            throw new RuntimeException('Paymob returned an invalid checkout response.');
        }

        $checkoutUrl = rtrim((string) config('payment.providers.paymob.base_url'), '/')
            .'/unifiedcheckout/?publicKey='.rawurlencode($publicKey)
            .'&clientSecret='.rawurlencode($clientSecret);

        return new PaymentIntent($reference, $checkoutUrl);
    }

    /** @param array<string, mixed> $payload */
    public function verifyNotification(array $payload): ?PaymentNotification
    {
        $signature = Arr::get($payload, 'hmac');
        $transaction = Arr::get($payload, 'obj');

        if (! is_string($signature) || ! is_array($transaction) || ! $this->hasValidSignature($transaction, $signature)) {
            return null;
        }

        $reference = data_get($transaction, 'order.merchant_order_id')
            ?? data_get($transaction, 'order.special_reference');
        $transactionId = Arr::get($transaction, 'id');
        $amountCents = Arr::get($transaction, 'amount_cents');
        $currency = Arr::get($transaction, 'currency');
        $success = Arr::get($transaction, 'success');

        if (! is_string($reference) || ! is_scalar($transactionId) || ! is_numeric($amountCents)
            || ! is_string($currency) || ! is_bool($success)) {
            return null;
        }

        $createdAt = Arr::get($transaction, 'created_at');

        return new PaymentNotification(
            $reference,
            (string) $transactionId,
            $success ? 'success' : 'failed',
            (int) $amountCents,
            $currency,
            is_string($createdAt) ? Carbon::parse($createdAt) : null,
        );
    }

    /** @param array<string, mixed> $transaction */
    private function hasValidSignature(array $transaction, string $signature): bool
    {
        $secret = (string) config('payment.providers.paymob.hmac_secret');

        if ($secret === '') {
            return false;
        }

        $values = array_map(
            fn (string $field): string => $this->hmacValue(data_get($transaction, $field)),
            self::HMAC_FIELDS,
        );
        $expected = hash_hmac('sha512', implode('', $values), $secret);

        return hash_equals($expected, $signature);
    }

    private function hmacValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '';
        }

        return (string) $value;
    }
}
