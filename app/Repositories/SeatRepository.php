<?php

namespace App\Repositories;

use App\Models\Event;
use App\Models\Reservation;
use App\Models\Seat;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Queries seat claims and locks seat rows inside the caller's transaction.
 */
final class SeatRepository
{
    /** @param array<int, string> $numbers
     * @return Collection<int, Seat>
     */
    public function lockForEventNumbers(Event $event, array $numbers): Collection
    {
        return Seat::query()
            ->whereBelongsTo($event)
            ->whereIn('number', $numbers)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Recheck late-payment claims inside a transaction, locking seats in consistent ID order.
     */
    public function areAvailableForCompletion(Reservation $reservation): bool
    {
        $seatIds = $reservation->seats()->orderBy('seats.id')->pluck('seats.id');
        $lockedSeatIds = Seat::query()->whereIn('id', $seatIds)->orderBy('id')->lockForUpdate()->pluck('id');

        if ($lockedSeatIds->count() !== $seatIds->count()) {
            return false;
        }

        return ! $this->hasActiveReservationFor($lockedSeatIds->all(), $reservation->id);
    }

    /** @param array<int, int> $seatIds */
    public function activeReservationsBySeat(array $seatIds): \Illuminate\Support\Collection
    {
        return DB::table('reservation_seat')
            ->join('reservations', 'reservations.id', '=', 'reservation_seat.reservation_id')
            ->whereIn('reservation_seat.seat_id', $seatIds)
            ->where(function ($query): void {
                $query->where('reservations.status', 'completed')
                    ->orWhere(function ($query): void {
                        $query->where('reservations.status', 'pending')->where('reservations.expires_at', '>', now());
                    });
            })
            ->select(['reservation_seat.seat_id', 'reservations.status'])
            ->get()
            ->groupBy('seat_id');
    }

    /** @param array<int, int> $seatIds */
    private function hasActiveReservationFor(array $seatIds, int $excludingReservationId): bool
    {
        return DB::table('reservation_seat')
            ->join('reservations', 'reservations.id', '=', 'reservation_seat.reservation_id')
            ->whereIn('reservation_seat.seat_id', $seatIds)
            ->where('reservations.id', '!=', $excludingReservationId)
            ->where(function ($query): void {
                $query->where('reservations.status', 'completed')
                    ->orWhere(function ($query): void {
                        $query->where('reservations.status', 'pending')->where('reservations.expires_at', '>', now());
                    });
            })
            ->exists();
    }
}
