<?php

namespace App\Repositories;

use App\Models\Event;
use App\Models\Seat;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reads public events and seats not blocked by sold seats or active holds.
 */
final class EventRepository
{
    /** @return Collection<int, Event> */
    public function all(): Collection
    {
        return Event::query()->orderBy('id')->get();
    }

    public function findOrFail(int $eventId): Event
    {
        return Event::query()->findOrFail($eventId);
    }

    /** @return Collection<int, Seat> */
    public function availableSeats(Event $event): Collection
    {
        return $event->seats()
            ->whereDoesntHave('reservations', function ($query): void {
                $query->where('status', 'completed')
                    ->orWhere(function ($query): void {
                        $query->where('status', 'pending')->where('expires_at', '>', now());
                    });
            })
            ->orderBy('number')
            ->get();
    }
}
