<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Presents reservation status and payment outcome separately using loaded relations.
 */
class ReservationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $latestPayment = $this->resource->relationLoaded('payments') ? $this->payments->first() : null;

        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'status' => $this->derived_status,
            'seats' => $this->resource->relationLoaded('seats') ? $this->seats->pluck('number')->sort()->values()->all() : [],
            'total' => sprintf('%d.%02d', intdiv($this->total_minor, 100), $this->total_minor % 100),
            'expires_at' => $this->expires_at->utc()->toIso8601String(),
            'last_payment' => $latestPayment === null ? null : [
                'status' => $latestPayment->status,
                'paid_at' => $latestPayment->paid_at?->utc()->toIso8601String(),
            ],
        ];
    }
}
