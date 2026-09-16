<?php

namespace App\Models;

use Database\Factories\ReservationSeatFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationSeat extends Model
{
    /** @use HasFactory<ReservationSeatFactory> */
    use HasFactory;

    protected $table = 'reservation_seats';

    protected $fillable = [
        'reservation_id',
        'seat_id',
        'unit_price',
        'released_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'released_at' => 'datetime',
            'unit_price' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * @return BelongsTo<Seat, $this>
     */
    public function seat(): BelongsTo
    {
        return $this->belongsTo(Seat::class);
    }

    /**
     * Claims that still hold their seat. Released rows stay as history.
     *
     * @param  Builder<ReservationSeat>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('released_at');
    }
}
