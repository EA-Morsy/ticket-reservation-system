<?php

namespace App\Repositories;

use App\Models\Event;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Persists reservations and loads their seats and latest payment outcome.
 */
final class ReservationRepository
{
    /** @return Collection<int, Reservation> */
    public function forUser(User $user): Collection
    {
        return Reservation::query()
            ->whereBelongsTo($user)
            ->with($this->detailRelations())
            ->latest('id')
            ->get();
    }

    public function loadDetails(Reservation $reservation): Reservation
    {
        return $reservation->load($this->detailRelations());
    }

    /**
     * Persist the agreed price and fixed 30-minute hold inside the caller's transaction.
     *
     * @param  array<int, int>  $seatIds
     */
    public function createPending(User $user, Event $event, array $seatIds): Reservation
    {
        $reservation = Reservation::query()->create([
            'user_id' => $user->id,
            'event_id' => $event->id,
            'status' => 'pending',
            'total_minor' => count($seatIds) * $event->seat_price_minor,
            'expires_at' => now()->addMinutes(30),
        ]);

        $reservation->seats()->attach($seatIds);

        return $reservation->load('seats');
    }

    public function findForUpdateWithSeats(int $reservationId): Reservation
    {
        return Reservation::query()->with('seats')->lockForUpdate()->findOrFail($reservationId);
    }

    /** @return array<string, mixed> */
    private function detailRelations(): array
    {
        return ['seats', 'payments' => fn ($query) => $query->latest('id')];
    }
}
