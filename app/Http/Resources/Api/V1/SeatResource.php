<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row from SeatAvailability::mapFor().
 *
 * The seat map never reveals who holds a seat: there is no reservation id and
 * no user id here, only whether the seat can still be booked.
 */
class SeatResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // PDO hands these back as strings; the contract says integers.
            'id' => (int) $this->id,
            'number' => $this->number,
            'price' => (int) $this->price,
            'status' => $this->status,
        ];
    }
}
