<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiErrorContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_errors_use_the_documented_shape_without_sensitive_details(): void
    {
        $this->getJson('/api/me')
            ->assertUnauthorized()
            ->assertExactJson(['success' => false, 'message' => 'Authentication is required.', 'error' => ['code' => 'UNAUTHENTICATED']]);

        $this->getJson('/api/events/999')
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'The requested resource was not found.', 'error' => ['code' => 'NOT_FOUND']]);
    }

    public function test_forbidden_and_payment_not_allowed_errors_follow_the_contract(): void
    {
        $event = Event::factory()->create();
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $reservation = Reservation::factory()->for($owner)->for($event)->create(['status' => 'completed']);

        $this->actingAs($otherUser, 'sanctum')->getJson("/api/reservations/{$reservation->id}")
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'You are not authorized to perform this action.', 'error' => ['code' => 'FORBIDDEN']]);

        $this->actingAs($owner, 'sanctum')->postJson("/api/reservations/{$reservation->id}/pay")
            ->assertConflict()
            ->assertExactJson(['success' => false, 'message' => 'Payment is not allowed for this reservation.', 'error' => ['code' => 'PAYMENT_NOT_ALLOWED']]);
    }
}
