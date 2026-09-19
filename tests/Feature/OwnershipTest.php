<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_view_the_reservation_and_other_user_gets_403(): void
    {
        $event = Event::factory()->create();
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $reservation = Reservation::factory()->for($owner)->for($event)->create();
        $reservation->seats()->attach(Seat::factory()->for($event)->create(['number' => 'A1']));

        $this->actingAs($owner, 'sanctum')->getJson("/api/reservations/{$reservation->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $reservation->id)
            ->assertJsonPath('data.status', 'pending');

        $this->actingAs($otherUser, 'sanctum')->getJson("/api/reservations/{$reservation->id}")
            ->assertForbidden();
    }

    public function test_pending_reservation_status_is_derived_as_expired_without_writing_the_row(): void
    {
        $this->freezeTime();
        $event = Event::factory()->create();
        $user = User::factory()->create();
        $reservation = Reservation::factory()->for($user)->for($event)->create(['expires_at' => now()->subSecond()]);
        $reservation->seats()->attach(Seat::factory()->for($event)->create(['number' => 'A1']));

        $this->actingAs($user, 'sanctum')->getJson("/api/reservations/{$reservation->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'expired');

        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'status' => 'pending']);
    }

    public function test_unauthenticated_reservation_detail_returns_401(): void
    {
        $this->getJson('/api/reservations/1')->assertUnauthorized();
    }
}
