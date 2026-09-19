<?php

namespace App\Http\Controllers\Api;

use App\Contracts\PaymentGateway;
use App\Exceptions\InvalidPaymentNotificationException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Reservation;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Starts owner payments and passes verified provider notifications to the payment service.
 */
class PaymentController extends Controller
{
    public function pay(Request $request, Reservation $reservation, PaymentService $paymentService): JsonResponse
    {
        $intent = $paymentService->initiate($request->user(), $reservation);

        return ApiResponse::success([
            'payment_reference' => $intent->reference,
            'hosted_checkout_url' => $intent->hostedCheckoutUrl,
            'expires_at' => $reservation->expires_at->utc()->toIso8601String(),
        ], 'Payment initiated successfully.');
    }

    public function webhook(Request $request, PaymentGateway $paymentGateway, PaymentService $paymentService): JsonResponse
    {
        $payload = $request->all();

        if (! array_key_exists('hmac', $payload) && $request->query('hmac') !== null) {
            $payload['hmac'] = $request->query('hmac');
        }

        $notification = $paymentGateway->verifyNotification($payload);

        if ($notification === null) {
            throw new InvalidPaymentNotificationException;
        }

        $paymentService->applyNotification($notification);

        return ApiResponse::success(null, 'Payment notification processed successfully.');
    }
}
