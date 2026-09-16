<?php

namespace App\Models;

use App\Domain\Reservations\ReservationStatus;
use App\Exceptions\Domain\IllegalTransitionException;
use Carbon\CarbonInterface;
use Database\Factories\ReservationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

class Reservation extends Model
{
    /** @use HasFactory<ReservationFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'user_id',
        'event_id',
        'status',
        'total_amount',
        'currency',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
            'expires_at' => 'datetime',
            'total_amount' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return HasMany<ReservationSeat, $this>
     */
    public function claims(): HasMany
    {
        return $this->hasMany(ReservationSeat::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * The status the rest of the system must act on.
     *
     * A pending reservation whose deadline has passed is already expired, even
     * though the sweep has not written that down yet. Every read and every
     * guard goes through here; nothing compares the stored status alone.
     */
    public function effectiveStatus(?CarbonInterface $now = null): ReservationStatus
    {
        $now ??= now();

        if ($this->status === ReservationStatus::Pending && $this->expires_at <= $now) {
            return ReservationStatus::Expired;
        }

        return $this->status;
    }

    public function isEffectivelyPending(?CarbonInterface $now = null): bool
    {
        return $this->effectiveStatus($now) === ReservationStatus::Pending;
    }

    public function isEffectivelyExpired(?CarbonInterface $now = null): bool
    {
        return $this->effectiveStatus($now) === ReservationStatus::Expired;
    }

    /**
     * Holds that are still live. `$now` is bound from PHP, never MySQL NOW(),
     * so Pest's travel() moves this query too.
     *
     * @param  Builder<Reservation>  $query
     */
    public function scopeEffectivelyPending(Builder $query, ?CarbonInterface $now = null): void
    {
        $query->where('status', ReservationStatus::Pending)
            ->where('expires_at', '>', $now ?? now());
    }

    /**
     * Holds the sweep has yet to materialise.
     *
     * @param  Builder<Reservation>  $query
     */
    public function scopeEffectivelyExpired(Builder $query, ?CarbonInterface $now = null): void
    {
        $query->where('status', ReservationStatus::Pending)
            ->where('expires_at', '<=', $now ?? now());
    }

    /**
     * The seat set this hold was created with — released claims included,
     * because that set is immutable after creation.
     *
     * @return list<int>
     */
    public function seatIds(): array
    {
        return $this->claims()
            ->orderBy('seat_id')
            ->pluck('seat_id')
            ->map(static fn (int|string $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @throws IllegalTransitionException
     */
    public function transitionTo(ReservationStatus $to): static
    {
        Log::withContext([
            'reservation_id' => $this->getKey(),
            'event_id' => $this->event_id,
        ]);

        $from = $this->status;

        if (! $from->canTransitionTo($to)) {
            Log::warning('Refused illegal reservation transition.', [
                'from' => $from->value,
                'to' => $to->value,
            ]);

            throw IllegalTransitionException::for('Reservation', $this->getKey(), $from, $to);
        }

        $this->status = $to;
        $this->save();

        Log::info('Reservation transitioned.', [
            'from' => $from->value,
            'to' => $to->value,
        ]);

        return $this;
    }
}
