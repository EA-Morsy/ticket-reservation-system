<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Http\Responses\ApiResponse;
use App\Models\Reservation;
use App\Repositories\EventRepository;
use App\Repositories\ReservationRepository;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Coordinates reservation creation and owner-authorized retrieval.
 */
class ReservationController extends Controller
{
    public function store(CreateReservationRequest $request, EventRepository $events, ReservationService $reservationService): JsonResponse
    {
        $validated = $request->validated();
        $event = $events->findOrFail($validated['event_id']);

        $reservation = $reservationService->create(
            $request->user(),
            $event,
            $validated['seat_numbers'],
        );

        return ApiResponse::success(ReservationResource::make($reservation), 'Reservation created successfully.', 201);
    }

    public function index(Request $request, ReservationRepository $reservations): JsonResponse
    {
        return ApiResponse::success(
            ReservationResource::collection($reservations->forUser($request->user())),
            'Reservations retrieved successfully.',
        );
    }

    public function show(Request $request, Reservation $reservation, ReservationRepository $reservations): JsonResponse
    {
        $this->authorize('view', $reservation);

        return ApiResponse::success(
            ReservationResource::make($reservations->loadDetails($reservation)),
            'Reservation retrieved successfully.',
        );
    }
}
