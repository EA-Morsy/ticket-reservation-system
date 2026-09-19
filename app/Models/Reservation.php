<?php

namespace App\Models;

use Database\Factories\ReservationFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stores a seat hold and derives expiry without requiring a cleanup job.
 */
class Reservation extends Model
{
    /** @use HasFactory<ReservationFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'event_id', 'status', 'total_minor', 'expires_at'];

    protected $appends = ['derived_status'];

    protected function casts(): array
    {
        return [
            'total_minor' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function seats(): BelongsToMany
    {
        return $this->belongsToMany(Seat::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    protected function derivedStatus(): Attribute
    {
        return Attribute::get(function (): string {
            if ($this->status === 'completed') {
                return 'completed';
            }

            return $this->expires_at->isFuture() ? 'pending' : 'expired';
        });
    }

    protected static function newFactory(): ReservationFactory
    {
        return ReservationFactory::new();
    }
}
