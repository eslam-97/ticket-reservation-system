<?php

namespace App\Actions\Reservations;

/**
 * What ReclaimExpiredHold found once it had the reservation row locked.
 *
 * `Confirmed` and `StillPending` both mean "the seats are not yours to take";
 * the caller decides what that is worth. The other two mean the hold is
 * settled and its seats are free.
 */
enum ReclaimOutcome
{
    /** Was a lapsed hold; it is now `expired` and its claims are released. */
    case Expired;

    /** Was already `expired` or `cancelled`; any stragglers were released. */
    case AlreadyReleased;

    /** Still a live hold under lock. Left untouched. */
    case StillPending;

    /** Confirmed under lock, so a late payment won the race. Left untouched. */
    case Confirmed;
}
