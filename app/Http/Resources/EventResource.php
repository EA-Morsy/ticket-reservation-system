<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Exposes public event details with an exact decimal seat price.
 */
class EventResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'seat_price' => sprintf('%d.%02d', intdiv($this->seat_price_minor, 100), $this->seat_price_minor % 100),
        ];
    }
}
