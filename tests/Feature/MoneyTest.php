<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Seat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_reservation_total_is_calculated_in_integer_minor_units_without_client_price_input(): void
    {
        $event = Event::factory()->create(['seat_price_minor' => 1010]);
        foreach (['A1', 'A2', 'A3'] as $number) {
            Seat::factory()->for($event)->create(['number' => $number]);
        }
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/reservations', [
            'event_id' => $event->id,
            'seat_numbers' => ['A1', 'A2', 'A3'],
            'total' => '0.01',
            'seat_price' => '0.01',
        ])->assertCreated()->assertJsonPath('data.total', '30.30');

        $this->assertDatabaseHas('reservations', ['total_minor' => 3030]);
    }
}
