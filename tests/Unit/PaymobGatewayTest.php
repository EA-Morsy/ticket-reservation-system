<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Reservation;
use App\Models\User;
use App\Payments\PaymobGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymobGatewayTest extends TestCase
{
    use RefreshDatabase;

    public function test_initiate_sends_configured_request_and_normalizes_hosted_checkout(): void
    {
        config()->set('payment.providers.paymob', [
            'secret_key' => 'test-secret-key', 'integration_id' => '42', 'hmac_secret' => 'hmac-secret',
            'base_url' => 'https://accept.paymob.test', 'public_key' => 'test-public-key',
        ]);
        Http::preventStrayRequests();
        Http::fake(['https://accept.paymob.test/v1/intention/' => Http::response(['client_secret' => 'client-secret'])]);
        $reservation = $this->reservation();

        $intent = app(PaymobGateway::class)->initiate($reservation);

        $this->assertStringStartsWith('paymob_', $intent->reference);
        $this->assertSame('https://accept.paymob.test/unifiedcheckout/?publicKey=test-public-key&clientSecret=client-secret', $intent->hostedCheckoutUrl);
        Http::assertSent(function (Request $request) use ($reservation): bool {
            return $request->url() === 'https://accept.paymob.test/v1/intention/'
                && $request->hasHeader('Authorization', 'Token test-secret-key')
                && $request['amount'] === $reservation->total_minor
                && $request['currency'] === 'EGP'
                && $request['payment_methods'] === [42];
        });
    }

    public function test_initiate_rejects_upstream_and_invalid_responses_without_network_access(): void
    {
        config()->set('payment.providers.paymob', [
            'secret_key' => 'test-secret-key', 'integration_id' => '42', 'hmac_secret' => 'hmac-secret',
            'base_url' => 'https://accept.paymob.test', 'public_key' => 'test-public-key',
        ]);
        Http::preventStrayRequests();
        Http::fake(['https://accept.paymob.test/v1/intention/' => Http::response([], 502)]);

        $this->expectExceptionMessage('Paymob checkout could not be initiated.');
        app(PaymobGateway::class)->initiate($this->reservation());
    }

    public function test_initiate_rejects_a_response_without_a_client_secret(): void
    {
        config()->set('payment.providers.paymob', [
            'secret_key' => 'test-secret-key', 'integration_id' => '42', 'hmac_secret' => 'hmac-secret',
            'base_url' => 'https://accept.paymob.test', 'public_key' => 'test-public-key',
        ]);
        Http::preventStrayRequests();
        Http::fake(['https://accept.paymob.test/v1/intention/' => Http::response([])]);

        $this->expectExceptionMessage('Paymob returned an invalid checkout response.');
        app(PaymobGateway::class)->initiate($this->reservation());
    }

    public function test_verify_notification_accepts_the_documented_hmac_fields_and_rejects_invalid_signature(): void
    {
        config()->set('payment.providers.paymob.hmac_secret', 'hmac-secret');
        $transaction = $this->transaction();
        $payload = ['obj' => $transaction, 'hmac' => $this->signature($transaction)];

        $notification = app(PaymobGateway::class)->verifyNotification($payload);

        $this->assertNotNull($notification);
        $this->assertSame('payment-reference', $notification->reference);
        $this->assertSame('transaction-1', $notification->providerTransactionId);
        $this->assertSame('success', $notification->status);
        $this->assertSame(15000, $notification->amountMinor);

        $payload['hmac'] = 'invalid';
        $this->assertNull(app(PaymobGateway::class)->verifyNotification($payload));
    }

    private function reservation(): Reservation
    {
        $event = Event::factory()->create(['seat_price_minor' => 15000]);

        return Reservation::factory()->for(User::factory())->for($event)->create(['total_minor' => 15000]);
    }

    /** @return array<string, mixed> */
    private function transaction(): array
    {
        return [
            'amount_cents' => 15000, 'created_at' => '2026-09-19T12:00:00Z', 'currency' => 'EGP',
            'error_occured' => false, 'has_parent_transaction' => false, 'id' => 'transaction-1',
            'integration_id' => 42, 'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false,
            'is_refunded' => false, 'is_standalone_payment' => true, 'is_voided' => false,
            'order' => ['id' => 'order-1', 'merchant_order_id' => 'payment-reference'], 'owner' => 1,
            'pending' => false, 'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card'],
            'success' => true,
        ];
    }

    /** @param array<string, mixed> $transaction */
    private function signature(array $transaction): string
    {
        $fields = [
            'amount_cents', 'created_at', 'currency', 'error_occured', 'has_parent_transaction',
            'id', 'integration_id', 'is_3d_secure', 'is_auth', 'is_capture', 'is_refunded',
            'is_standalone_payment', 'is_voided', 'order.id', 'owner', 'pending', 'source_data.pan',
            'source_data.sub_type', 'source_data.type', 'success',
        ];
        $message = collect($fields)->map(function (string $field) use ($transaction): string {
            $value = data_get($transaction, $field);

            return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        })->implode('');

        return hash_hmac('sha512', $message, 'hmac-secret');
    }
}
