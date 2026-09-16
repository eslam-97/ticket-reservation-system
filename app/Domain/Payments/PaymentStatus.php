<?php

namespace App\Domain\Payments;

/**
 * The payment-attempt state machine.
 *
 * Strictly forward-only: no status here ever needs undoing. `cancelled` is
 * written only once the provider has confirmed the session cannot complete,
 * which is what keeps `initiated → cancelled` from ever needing to run
 * backwards when a session completes anyway.
 */
enum PaymentStatus: string
{
    case Initiated = 'initiated';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Mismatched = 'mismatched';
    case RefundPending = 'refund_pending';
    case Refunded = 'refunded';

    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Initiated => in_array(
                $to,
                [self::Succeeded, self::Failed, self::Expired, self::Cancelled, self::Mismatched],
                true,
            ),
            self::Succeeded => $to === self::RefundPending,
            self::RefundPending => $to === self::Refunded,
            self::Failed, self::Expired, self::Cancelled, self::Mismatched, self::Refunded => false,
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Failed, self::Expired, self::Cancelled, self::Mismatched, self::Refunded => true,
            self::Initiated, self::Succeeded, self::RefundPending => false,
        };
    }
}
