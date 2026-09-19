<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Http\Resources\SeatResource;
use App\Http\Responses\ApiResponse;
use App\Models\Event;
use App\Repositories\EventRepository;
use Illuminate\Http\JsonResponse;

/**
 * Serves public event details and currently available seats.
 */
class EventController extends Controller
{
    public function index(EventRepository $events): JsonResponse
    {
        return ApiResponse::success(EventResource::collection($events->all()), 'Events retrieved successfully.');
    }

    public function show(Event $event): JsonResponse
    {
        return ApiResponse::success(EventResource::make($event), 'Event retrieved successfully.');
    }

    public function seats(Event $event, EventRepository $events): JsonResponse
    {
        return ApiResponse::success([
            'event_id' => $event->id,
            'seats' => SeatResource::collection($events->availableSeats($event)),
        ], 'Available seats retrieved successfully.');
    }
}
