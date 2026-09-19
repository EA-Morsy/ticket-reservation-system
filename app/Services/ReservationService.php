<?php

namespace App\Services;

use App\Exceptions\SeatUnavailableException;
use App\Models\Event;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\User;
use App\Repositories\ReservationRepository;
use App\Repositories\SeatRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reserves the entire seat selection atomically and checks late-payment availability.
 */
class ReservationService
{
    public function __construct(
        private ReservationRepository $reservations,
        private SeatRepository $seats,
    ) {}

    /**
     * Lock seats before checking availability so competing requests cannot both acquire them.
     *
     * @param  array<int, string>  $seatNumbers
     */
    public function create(User $user, Event $event, array $seatNumbers): Reservation
    {
        return DB::transaction(function () use ($user, $event, $seatNumbers): Reservation {
            $seats = $this->seats->lockForEventNumbers($event, $seatNumbers);

            $unavailableReasons = $this->unavailableReasons($seats);

            if ($unavailableReasons !== []) {
                throw new SeatUnavailableException($unavailableReasons);
            }

            return $this->reservations->createPending($user, $event, $seats->modelKeys());
        });
    }

    public function seatsAreAvailableForCompletion(Reservation $reservation): bool
    {
        return $this->seats->areAvailableForCompletion($reservation);
    }

    /** @param Collection<int, Seat> $seats
     * @return array<int, array{number: string, reason: string}>
     */
    private function unavailableReasons(Collection $seats): array
    {
        $reservationsBySeat = $this->seats->activeReservationsBySeat($seats->modelKeys());

        return $seats
            ->filter(fn (Seat $seat): bool => $reservationsBySeat->has($seat->id))
            ->map(function (Seat $seat) use ($reservationsBySeat): array {
                $isSold = $reservationsBySeat[$seat->id]
                    ->contains(fn ($reservation): bool => $reservation->status === 'completed');

                return ['number' => $seat->number, 'reason' => $isSold ? 'sold' : 'held'];
            })
            ->values()
            ->all();
    }
}
