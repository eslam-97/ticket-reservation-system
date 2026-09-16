<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Seat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Seat>
 */
class SeatFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            // Unique per event, which is what the (event_id, number) index wants.
            'number' => fake()->unique()->bothify('?##'),
            'price' => 25_000,
        ];
    }

    public function pricedAt(int $minorUnits): static
    {
        return $this->state(fn (): array => [
            'price' => $minorUnits,
        ]);
    }
}
