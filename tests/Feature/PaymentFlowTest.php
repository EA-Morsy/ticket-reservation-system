<?php

namespace Tests\Feature;

use App\Contracts\PaymentGateway;
use App\Models\Event;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_initiate_payment_for_an_active_pending_reservation(): void
    {
        [$user, $reservation] = $this->reservation();

        $this->actingAs($user, 'sanctum')->postJson("/api/reservations/{$reservation->id}/pay")
            ->assertOk()
            ->assertJsonPath('data.expires_at', $reservation->expires_at->utc()->toIso8601String())
            ->assertJsonStructure(['data' => ['payment_reference', 'hosted_checkout_url']]);

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('payments', ['reservation_id' => $reservation->id, 'amount_minor' => 15000, 'currency' => 'EGP', 'status' => 'pending']);
    }

    public function test_gateway_initiation_failure_leaves_no_payment_attempt_or_reservation_mutation(): void
    {
        [$user, $reservation] = $this->reservation();
        $originalExpiresAt = $reservation->expires_at;
        $gateway = Mockery::mock(PaymentGateway::class);
        $gateway->shouldReceive('initiate')->once()->andThrow(new RuntimeException('provider secret must not leak'));
        $this->app->instance(PaymentGateway::class, $gateway);

        $this->actingAs($user, 'sanctum')->postJson("/api/reservations/{$reservation->id}/pay")
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'PAYMENT_UNAVAILABLE')
            ->assertJsonMissing(['secret']);

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => 'pending',
            'total_minor' => 15000,
            'expires_at' => $originalExpiresAt,
        ]);
    }

    public function test_non_owner_and_unauthenticated_users_cannot_initiate_payment(): void
    {
        [$owner, $reservation] = $this->reservation();
        $otherUser = User::factory()->create();

        $this->actingAs($otherUser, 'sanctum')->postJson("/api/reservations/{$reservation->id}/pay")->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->postJson("/api/reservations/{$reservation->id}/pay")->assertUnauthorized();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_completed_or_expired_reservation_cannot_start_payment_or_restore_hold(): void
    {
        [$user, $completedReservation] = $this->reservation(['status' => 'completed']);
        [, $expiredReservation] = $this->reservation(['user_id' => $user->id, 'expires_at' => now()->subSecond()]);

        $this->actingAs($user, 'sanctum')->postJson("/api/reservations/{$completedReservation->id}/pay")
            ->assertConflict()->assertJsonPath('error.code', 'PAYMENT_NOT_ALLOWED');
        $this->actingAs($user, 'sanctum')->postJson("/api/reservations/{$expiredReservation->id}/pay")
            ->assertConflict()->assertJsonPath('error.code', 'PAYMENT_NOT_ALLOWED');
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseHas('reservations', ['id' => $expiredReservation->id, 'status' => 'pending']);
    }

    public function test_failed_payment_can_be_retried_without_changing_total_seats_or_expiry(): void
    {
        [$user, $reservation] = $this->reservation();
        $seatId = $reservation->seats()->value('seats.id');
        Payment::factory()->for($reservation)->create([
            'amount_minor' => $reservation->total_minor,
            'status' => 'failed',
            'currency' => 'EGP',
        ]);

        $this->actingAs($user, 'sanctum')->postJson("/api/reservations/{$reservation->id}/pay")->assertOk();

        $this->assertDatabaseCount('payments', 2);
        $this->assertDatabaseHas('reservation_seat', ['reservation_id' => $reservation->id, 'seat_id' => $seatId]);
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'total_minor' => 15000, 'expires_at' => $reservation->expires_at]);
    }

    /** @param array<string, mixed> $attributes
     * @return array{0: User, 1: Reservation}
     */
    private function reservation(array $attributes = []): array
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['seat_price_minor' => 15000]);
        $reservation = Reservation::factory()->for($user)->for($event)->create(array_merge([
            'total_minor' => 15000,
            'expires_at' => now()->addMinutes(30),
        ], $attributes));
        $reservation->seats()->attach(Seat::factory()->for($event)->create(['number' => 'A1']));

        return [$user, $reservation->fresh('seats')];
    }
}
