<?php

namespace Database\Factories;

use App\Models\Reservation;
use App\Models\ReservationSeat;
use App\Models\Seat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReservationSeat>
 */
class ReservationSeatFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reservation_id' => Reservation::factory(),
            'seat_id' => Seat::factory(),
            'unit_price' => 25_000,
            'released_at' => null,
        ];
    }

    public function released(): static
    {
        return $this->state(fn (): array => [
            'released_at' => now(),
        ]);
    }
}
