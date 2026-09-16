<?php

namespace Database\Factories;

use App\Domain\Payments\PaymentStatus;
use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reservation_id' => Reservation::factory(),
            'provider' => 'fake',
            'provider_ref' => null,
            'amount' => 25_000,
            'currency' => 'EGP',
            'status' => PaymentStatus::Initiated,
            'failure_code' => null,
            'raw_payload' => null,
            'processed_at' => null,
            'attempts' => 0,
            'next_retry_at' => null,
        ];
    }

    /**
     * An attempt for a reservation, with the amount and currency snapshot the
     * webhook will check against.
     */
    public function forReservation(Reservation $reservation): static
    {
        return $this->state(fn (): array => [
            'reservation_id' => $reservation->id,
            'amount' => $reservation->total_amount,
            'currency' => $reservation->currency,
        ]);
    }

    public function withStatus(PaymentStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
        ]);
    }

    public function withRef(string $ref = 'cs_test_123'): static
    {
        return $this->state(fn (): array => [
            'provider_ref' => $ref,
        ]);
    }
}
