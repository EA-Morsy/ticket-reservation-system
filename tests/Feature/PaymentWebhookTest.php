<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\User;
use App\Payments\FakePaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_success_before_expiry_completes_reservation_and_duplicate_is_a_no_op(): void
    {
        [$reservation, $payment] = $this->payment();
        $payload = $this->fakeGateway()->notificationPayload($payment->reference, 'success', 15000, 'EGP');

        $this->postJson('/api/payments/webhook', $payload)->assertOk();
        $this->postJson('/api/payments/webhook', $payload)->assertOk();

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'success']);
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'completed']);
    }

    public function test_invalid_signature_and_unknown_reference_have_no_state_change(): void
    {
        [, $payment] = $this->payment();
        $payload = $this->fakeGateway()->notificationPayload($payment->reference, 'success', 15000, 'EGP');
        $payload['signature'] = 'invalid';

        $this->postJson('/api/payments/webhook', $payload)->assertForbidden();
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending']);

        $payload = $this->fakeGateway()->notificationPayload('unknown', 'success', 15000, 'EGP');
        $this->postJson('/api/payments/webhook', $payload)->assertNotFound();
    }

    public function test_verified_failure_keeps_the_original_hold_and_does_not_undo_completed_reservation(): void
    {
        [$reservation, $payment] = $this->payment();
        $payload = $this->fakeGateway()->notificationPayload($payment->reference, 'failed', 15000, 'EGP');

        $this->postJson('/api/payments/webhook', $payload)->assertOk();
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'failed']);
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'pending', 'expires_at' => $reservation->expires_at]);
    }

    public function test_mismatched_notification_is_recorded_without_completing_reservation(): void
    {
        [$reservation, $payment] = $this->payment();
        $payload = $this->fakeGateway()->notificationPayload($payment->reference, 'success', 14999, 'EGP');

        $this->postJson('/api/payments/webhook', $payload)->assertOk();
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'success']);
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'pending']);
    }

    public function test_late_success_completes_only_when_all_original_seats_remain_available(): void
    {
        [$reservation, $payment] = $this->payment(['expires_at' => now()->subSecond()]);
        $payload = $this->fakeGateway()->notificationPayload($payment->reference, 'success', 15000, 'EGP');

        $this->postJson('/api/payments/webhook', $payload)->assertOk();
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'completed']);
    }

    public function test_late_success_stays_uncompleted_when_any_original_seat_was_reacquired(): void
    {
        [$reservation, $payment] = $this->payment(['expires_at' => now()->subSecond()]);
        $newReservation = Reservation::factory()->for(User::factory())->for($reservation->event)->create([
            'total_minor' => 15000,
            'expires_at' => now()->addMinutes(30),
        ]);
        $newReservation->seats()->attach($reservation->seats()->first());
        $payload = $this->fakeGateway()->notificationPayload($payment->reference, 'success', 15000, 'EGP');

        $this->postJson('/api/payments/webhook', $payload)->assertOk();

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'success']);
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'pending']);
        $this->assertDatabaseHas('reservations', ['id' => $newReservation->id, 'status' => 'pending']);
    }

    /** @param array<string, mixed> $attributes
     * @return array{0: Reservation, 1: Payment}
     */
    private function payment(array $attributes = []): array
    {
        $event = Event::factory()->create(['seat_price_minor' => 15000]);
        $reservation = Reservation::factory()->for(User::factory())->for($event)->create(array_merge([
            'total_minor' => 15000,
            'expires_at' => now()->addMinutes(30),
        ], $attributes));
        $reservation->seats()->attach(Seat::factory()->for($event)->create(['number' => 'A1']));
        $payment = Payment::factory()->for($reservation)->create(['amount_minor' => 15000, 'currency' => 'EGP']);

        return [$reservation, $payment];
    }

    private function fakeGateway(): FakePaymentGateway
    {
        return app(FakePaymentGateway::class);
    }
}
