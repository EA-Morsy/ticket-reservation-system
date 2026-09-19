<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;

/**
 * Restricts reservation viewing and payment initiation to its owner.
 */
class ReservationPolicy
{
    public function view(User $user, Reservation $reservation): bool
    {
        return $user->id === $reservation->user_id;
    }

    public function pay(User $user, Reservation $reservation): bool
    {
        return $this->view($user, $reservation);
    }
}
