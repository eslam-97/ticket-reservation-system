<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Reservation;
use App\Models\ReservationSeat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Reservation
 */
class ReservationResource extends JsonResource
{
    /**
     * Relations this resource reads. Load them before rendering.
     *
     * @var list<string>
     */
    public const RELATIONS = ['event', 'claims.seat', 'payments'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // The status callers must act on: a hold past its deadline reads
            // as expired here even though the sweep has not run yet.
            'status' => $this->effectiveStatus()->value,
            // What the row actually says, so the difference is visible rather
            // than mysterious when a client compares the two.
            'stored_status' => $this->status->value,
            'expires_at' => $this->expires_at->utc()->toIso8601String(),
            'total_amount' => (int) $this->total_amount,
            'currency' => $this->currency,
            'event' => [
                'id' => (int) $this->event->id,
                'name' => $this->event->name,
                'starts_at' => $this->event->starts_at->utc()->toIso8601String(),
            ],
            'seats' => $this->seats(),
            'payments' => PaymentAttemptResource::collection($this->payments),
            'created_at' => $this->created_at?->utc()->toIso8601String(),
        ];
    }

    /**
     * Every claim the hold was created with, released ones included: the seat
     * set is immutable, and `released_at` is how a client sees what happened.
     *
     * @return list<array<string, mixed>>
     */
    private function seats(): array
    {
        return $this->claims
            ->sortBy('seat_id')
            ->map(fn (ReservationSeat $claim): array => [
                'id' => (int) $claim->seat_id,
                'number' => $claim->seat->number,
                'unit_price' => (int) $claim->unit_price,
                'released_at' => $claim->released_at?->utc()->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
