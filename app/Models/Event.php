<?php

namespace App\Models;

use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stores the uniform seat price in minor units and owns the event's seats.
 */
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    protected $fillable = ['name', 'seat_price_minor'];

    protected function casts(): array
    {
        return ['seat_price_minor' => 'integer'];
    }

    public function seats(): HasMany
    {
        return $this->hasMany(Seat::class);
    }

    protected static function newFactory(): EventFactory
    {
        return EventFactory::new();
    }
}
