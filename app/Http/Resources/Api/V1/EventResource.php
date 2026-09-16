<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Event;
use App\Services\Seats\SeatAvailability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/**
 * @mixin Event
 */
class EventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            // Stored UTC, returned ISO 8601: the client counts down from
            // server time and never consults its own clock.
            'starts_at' => $this->starts_at->utc()->toIso8601String(),
            'currency' => $this->currency,
            'seats_total' => $this->count('seats_total'),
            'seats_available' => $this->count('seats_available'),
        ];
    }

    /**
     * Counts come from SeatAvailability::attachCounts(). Missing them would
     * otherwise render as a plausible-looking zero, so say so loudly instead.
     */
    private function count(string $attribute): int
    {
        $value = $this->resource->getAttribute($attribute);

        if ($value === null) {
            throw new LogicException(sprintf(
                'Event %s has no %s attribute: call %s::attachCounts() before rendering it.',
                $this->resource->getKey(),
                $attribute,
                SeatAvailability::class,
            ));
        }

        return (int) $value;
    }
}
