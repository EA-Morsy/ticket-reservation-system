<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventsAndSeatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_events_can_be_listed_and_shown_without_seat_details(): void
    {
        $event = Event::factory()->create(['name' => 'Cairo Jazz Night', 'seat_price_minor' => 15000]);

        $this->getJson('/api/events')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Cairo Jazz Night')
            ->assertJsonPath('data.0.seat_price', '150.00');

        $this->getJson("/api/events/{$event->id}")
            ->assertOk()
            ->assertExactJson(['success' => true, 'message' => 'Event retrieved successfully.', 'data' => ['id' => $event->id, 'name' => 'Cairo Jazz Night', 'seat_price' => '150.00']]);
    }

    public function test_unknown_event_returns_404(): void
    {
        $this->getJson('/api/events/999')->assertNotFound();
        $this->getJson('/api/events/999/seats')->assertNotFound();
    }

    public function test_seats_endpoint_hides_active_holds_and_completed_reservations_but_releases_expired_holds(): void
    {
        $event = Event::factory()->create();
        $availableSeat = Seat::factory()->for($event)->create(['number' => 'A1']);
        $heldSeat = Seat::factory()->for($event)->create(['number' => 'A2']);
        $soldSeat = Seat::factory()->for($event)->create(['number' => 'A3']);
        $expiredSeat = Seat::factory()->for($event)->create(['number' => 'A4']);
        $user = User::factory()->create();

        Reservation::factory()->for($user)->for($event)->create(['expires_at' => now()->addMinute()])->seats()->attach($heldSeat);
        Reservation::factory()->completed()->for($user)->for($event)->create()->seats()->attach($soldSeat);
        Reservation::factory()->for($user)->for($event)->create(['expires_at' => now()->subSecond()])->seats()->attach($expiredSeat);

        $this->getJson("/api/events/{$event->id}/seats")
            ->assertOk()
            ->assertExactJson(['success' => true, 'message' => 'Available seats retrieved successfully.', 'data' => ['event_id' => $event->id, 'seats' => [
                ['id' => $availableSeat->id, 'number' => 'A1'],
                ['id' => $expiredSeat->id, 'number' => 'A4'],
            ]]]);
    }
}
