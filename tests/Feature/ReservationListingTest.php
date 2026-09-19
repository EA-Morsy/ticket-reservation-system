<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_listing_contains_only_the_authenticated_users_reservations(): void
    {
        $event = Event::factory()->create();
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $ownedReservation = Reservation::factory()->for($owner)->for($event)->create();
        $otherReservation = Reservation::factory()->for($otherUser)->for($event)->create();
        $ownedReservation->seats()->attach(Seat::factory()->for($event)->create(['number' => 'A1']));
        $otherReservation->seats()->attach(Seat::factory()->for($event)->create(['number' => 'A2']));

        $this->actingAs($owner, 'sanctum')->getJson('/api/reservations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownedReservation->id)
            ->assertJsonMissing(['id' => $otherReservation->id]);
    }

    public function test_listing_returns_401_when_no_token_is_provided(): void
    {
        $this->getJson('/api/reservations')->assertUnauthorized();
    }
}
