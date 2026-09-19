<?php

namespace Database\Seeders;

use App\Models\Event;
use Illuminate\Database\Seeder;

class EventSeeder extends Seeder
{
    public function run(): void
    {
        $events = [
            ['name' => 'Cairo Jazz Night', 'seat_price_minor' => 15000, 'seats' => ['A1', 'A2', 'A3', 'B1', 'B2', 'B3']],
            ['name' => 'Theatre Evening', 'seat_price_minor' => 10000, 'seats' => ['A1', 'A2', 'A3', 'B1', 'B2', 'B3']],
        ];

        foreach ($events as $eventData) {
            $event = Event::query()->create([
                'name' => $eventData['name'],
                'seat_price_minor' => $eventData['seat_price_minor'],
            ]);

            foreach ($eventData['seats'] as $number) {
                $event->seats()->create(['number' => $number]);
            }
        }
    }
}
