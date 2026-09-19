<?php

namespace App\Services;

use App\Contracts\PaymentGateway;
use App\Exceptions\PaymentGatewayUnavailableException;
use App\Exceptions\PaymentNotAllowedException;
use App\Exceptions\UnknownPaymentReferenceException;
use App\Models\Reservation;
use App\Models\User;
use App\Payments\PaymentIntent;
use App\Payments\PaymentNotification;
use App\Repositories\PaymentRepository;
use App\Repositories\ReservationRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Coordinates payment attempts and idempotent reservation completion.
 */
class PaymentService
{
    public function __construct(
        private PaymentGateway $paymentGateway,
        private PaymentRepository $payments,
        private ReservationRepository $reservations,
        private ReservationService $reservationService,
    ) {}

    /**
     * Start checkout before persisting an attempt so gateway failure leaves no orphan payment.
     */
    public function initiate(User $user, Reservation $reservation): PaymentIntent
    {
        Gate::forUser($user)->authorize('pay', $reservation);

        if ($reservation->status !== 'pending' || ! $reservation->expires_at->isFuture()) {
            throw new PaymentNotAllowedException;
        }

        try {
            $intent = $this->paymentGateway->initiate($reservation);
        } catch (RuntimeException $exception) {
            throw new PaymentGatewayUnavailableException($exception);
        }

        $this->payments->createPending($reservation, $intent);

        return $intent;
    }

    /**
     * Record the first verified outcome; return false for an already processed payment.
     */
    public function applyNotification(PaymentNotification $notification): bool
    {
        try {
            return DB::transaction(function () use ($notification): bool {
                $payment = $this->payments->findByReferenceForUpdate($notification->reference);

                if ($payment === null) {
                    throw new UnknownPaymentReferenceException;
                }

                if ($payment->status !== 'pending') {
                    return false;
                }

                $reservation = $this->reservations->findForUpdateWithSeats($payment->reservation_id);

                $payment->provider_transaction_id = $notification->providerTransactionId;
                $payment->status = $notification->status;
                $payment->paid_at = $notification->status === 'success' ? $notification->paidAt : null;
                $payment->save();

                // Keep the payment outcome even when it cannot complete the reservation.
                if ($notification->amountMinor !== $reservation->total_minor
                    || $notification->currency !== config('payment.currency')
                    || $notification->status === 'failed'
                    || $reservation->status === 'completed') {
                    return true;
                }

                if ($reservation->expires_at->isFuture()) {
                    $reservation->update(['status' => 'completed']);

                    return true;
                }

                // An expired hold can complete only after all original seats are locked and rechecked.
                if ($this->reservationService->seatsAreAvailableForCompletion($reservation)) {
                    $reservation->update(['status' => 'completed']);
                }

                return true;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if ($this->payments->hasProviderTransaction($notification->providerTransactionId)) {
                return false;
            }

            throw $exception;
        }
    }
}
