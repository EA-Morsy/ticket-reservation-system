<?php

namespace App\Models;

use Database\Factories\SeatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Represents an event seat whose availability is derived from its reservations.
 */
class Seat extends Model
{
    /** @use HasFactory<SeatFactory> */
    use HasFactory;

    protected $fillable = ['event_id', 'number'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function reservations(): BelongsToMany
    {
        return $this->belongsToMany(Reservation::class);
    }

    protected static function newFactory(): SeatFactory
    {
        return SeatFactory::new();
    }
}
