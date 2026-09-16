<?php

namespace Database\Factories;

use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->sentence(3),
            // Far enough out that holds get the full 30 minutes by default.
            'starts_at' => now()->addDays(fake()->numberBetween(3, 60)),
            'currency' => 'EGP',
        ];
    }

    /**
     * An event too close to start for a hold to run its full length, so
     * expires_at gets clamped to starts_at.
     */
    public function startingIn(int $minutes): static
    {
        return $this->state(fn (): array => [
            'starts_at' => now()->addMinutes($minutes),
        ]);
    }

    public function past(): static
    {
        return $this->state(fn (): array => [
            'starts_at' => now()->subDay(),
        ]);
    }
}
