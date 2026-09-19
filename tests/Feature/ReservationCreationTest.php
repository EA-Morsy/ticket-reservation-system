<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationCreationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_an_active_reservation_with_201(): void
    {
        $this->freezeTime();
        $event = Event::factory()->create(['seat_price_minor' => 1010]);
        $seat = Seat::factory()->for($event)->create(['number' => 'A1']);
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/reservations', [
            'event_id' => $event->id,
            'seat_numbers' => ['A1'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.seats.0', 'A1')
            ->assertJsonPath('data.total', '10.10')
            ->assertJsonPath('data.expires_at', now()->addMinutes(30)->toIso8601String());
        $this->assertDatabaseHas('reservations', ['user_id' => $user->id, 'event_id' => $event->id, 'total_minor' => 1010]);
        $this->assertDatabaseHas('reservation_seat', ['seat_id' => $seat->id]);
    }

    public function test_reservation_requires_valid_distinct_seats_from_the_selected_event(): void
    {
        $event = Event::factory()->create();
        Seat::factory()->for($event)->create(['number' => 'A1']);
        $otherEvent = Event::factory()->create();
        Seat::factory()->for($otherEvent)->create(['number' => 'B1']);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/reservations', [
            'event_id' => $event->id,
            'seat_numbers' => ['A1', 'A1'],
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR')->assertJsonStructure(['error' => ['details']]);

        $this->actingAs($user, 'sanctum')->postJson('/api/reservations', [
            'event_id' => $event->id,
            'seat_numbers' => ['B1'],
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR')->assertJsonStructure(['error' => ['details']]);
    }

    public function test_reservation_rejects_an_empty_or_unknown_seat_selection_with_422(): void
    {
        $event = Event::factory()->create();
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/reservations', [
            'event_id' => $event->id,
            'seat_numbers' => [],
        ])->assertUnprocessable()->assertJsonPath('error.details.seat_numbers.0', 'The seat numbers field is required.');

        $this->actingAs($user, 'sanctum')->postJson('/api/reservations', [
            'event_id' => $event->id,
            'seat_numbers' => ['Z99'],
        ])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR')->assertJsonStructure(['error' => ['details']]);
    }

    public function test_conflicting_seats_return_409_without_creating_a_partial_reservation(): void
    {
        $event = Event::factory()->create();
        $heldSeat = Seat::factory()->for($event)->create(['number' => 'A1']);
        $availableSeat = Seat::factory()->for($event)->create(['number' => 'A2']);
        $holder = User::factory()->create();
        $user = User::factory()->create();
        Reservation::factory()->for($holder)->for($event)->create(['expires_at' => now()->addMinute()])->seats()->attach($heldSeat);

        $this->actingAs($user, 'sanctum')->postJson('/api/reservations', [
            'event_id' => $event->id,
            'seat_numbers' => ['A1', 'A2'],
        ])->assertConflict()
            ->assertJsonPath('error.code', 'SEAT_UNAVAILABLE')
            ->assertJsonPath('error.details.seats.0.number', 'A1')
            ->assertJsonPath('error.details.seats.0.reason', 'held');

        $this->assertDatabaseCount('reservations', 1);
        $this->assertDatabaseMissing('reservation_seat', ['seat_id' => $availableSeat->id]);
    }

    public function test_sold_and_already_held_seats_are_unavailable_to_every_user(): void
    {
        $event = Event::factory()->create();
        $soldSeat = Seat::factory()->for($event)->create(['number' => 'A1']);
        $heldSeat = Seat::factory()->for($event)->create(['number' => 'A2']);
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Reservation::factory()->completed()->for($user)->for($event)->create()->seats()->attach($soldSeat);
        Reservation::factory()->for($user)->for($event)->create(['expires_at' => now()->addMinute()])->seats()->attach($heldSeat);

        $this->actingAs($otherUser, 'sanctum')->postJson('/api/reservations', [
            'event_id' => $event->id,
            'seat_numbers' => ['A1'],
        ])->assertConflict()->assertJsonPath('error.details.seats.0.reason', 'sold');

        $this->actingAs($user, 'sanctum')->postJson('/api/reservations', [
            'event_id' => $event->id,
            'seat_numbers' => ['A2'],
        ])->assertConflict()->assertJsonPath('error.details.seats.0.reason', 'held');
    }

    public function test_unauthenticated_reservation_creation_returns_401(): void
    {
        $this->postJson('/api/reservations', ['event_id' => 1, 'seat_numbers' => ['A1']])
            ->assertUnauthorized();
    }
}
