<?php

namespace App\Exceptions\Domain;

use App\Domain\Reservations\ReservationStatus;
use App\Exceptions\DomainException;
use App\Models\Reservation;
use Carbon\CarbonInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * The reservation is no longer a live hold, so it cannot be paid or cancelled.
 *
 * Expiry gets its own code because it is the one the client can explain to a
 * user and recover from by starting a new hold.
 */
class ReservationNotPendingException extends DomainException
{
    public function __construct(ReservationStatus $status)
    {
        parent::__construct(
            $status === ReservationStatus::Expired ? 'reservation_expired' : 'reservation_not_pending',
            match ($status) {
                ReservationStatus::Expired => 'This reservation has expired.',
                ReservationStatus::Cancelled => 'This reservation has been cancelled.',
                ReservationStatus::Confirmed => 'This reservation has already been confirmed.',
                ReservationStatus::Pending => 'This reservation is no longer pending.',
            },
            Response::HTTP_GONE,
            ['status' => $status->value],
        );
    }

    /**
     * Built from the effective status, never the stored one.
     */
    public static function for(Reservation $reservation, ?CarbonInterface $now = null): self
    {
        return new self($reservation->effectiveStatus($now));
    }
}
