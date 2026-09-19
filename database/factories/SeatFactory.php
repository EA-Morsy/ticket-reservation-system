<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Seat;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Seat> */
class SeatFactory extends Factory
{
    protected $model = Seat::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'number' => fake()->unique()->bothify('A#'),
        ];
    }
}
