<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Reservation> */
class ReservationFactory extends Factory
{
    protected $model = Reservation::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event_id' => Event::factory(),
            'status' => 'pending',
            'total_minor' => 15000,
            'expires_at' => now()->addMinutes(30),
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => ['status' => 'completed']);
    }
}
