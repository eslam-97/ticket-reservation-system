<?php

namespace Database\Factories;

use App\Domain\Reservations\ReservationStatus;
use App\Models\Event;
use App\Models\Reservation;
use App\Models\ReservationSeat;
use App\Models\Seat;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reservation>
 */
class ReservationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event_id' => Event::factory(),
            'status' => ReservationStatus::Pending,
            'total_amount' => 0,
            'currency' => 'EGP',
            'expires_at' => now()->addMinutes(config('reservations.hold_minutes', 30)),
        ];
    }

    /**
     * Give the hold `$n` seats on its own event, with the price snapshots that
     * make total_amount true. Seats are created here rather than passed in so
     * the caller cannot accidentally claim another event's seats.
     */
    public function withSeats(int $n = 1, ?int $unitPrice = null): static
    {
        return $this->afterCreating(function (Reservation $reservation) use ($n, $unitPrice): void {
            $seats = Seat::factory()
                ->count($n)
                ->for($reservation->event)
                ->when($unitPrice !== null, fn ($factory) => $factory->pricedAt($unitPrice))
                ->create();

            foreach ($seats as $seat) {
                ReservationSeat::factory()->create([
                    'reservation_id' => $reservation->id,
                    'seat_id' => $seat->id,
                    'unit_price' => $seat->price,
                ]);
            }

            // The snapshot: summed from the claims, never recomputed later.
            $reservation->total_amount = $seats->sum('price');
            $reservation->save();
        });
    }

    /**
     * A hold whose deadline has already passed but which the sweep has not
     * materialised yet — stored `pending`, effectively `expired`.
     */
    public function stale(): static
    {
        return $this->state(fn (): array => [
            'status' => ReservationStatus::Pending,
            'expires_at' => now()->subMinute(),
        ]);
    }

    public function withStatus(ReservationStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
        ]);
    }
}
