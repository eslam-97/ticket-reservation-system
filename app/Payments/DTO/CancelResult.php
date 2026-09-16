<?php

namespace App\Payments\DTO;

/**
 * The outcome of asking the provider to kill a hosted session.
 *
 * `confirmed` is the only thing that licenses writing `cancelled` on an
 * attempt: it means the session can no longer complete. Anything else leaves
 * the attempt `initiated` for the webhook or reconcile to settle, which is
 * what keeps the payment machine free of backward edges.
 */
final readonly class CancelResult
{
    public const EXPIRED = 'expired';

    public const ALREADY_COMPLETED = 'already_completed';

    public const NOT_FOUND = 'not_found';

    public const PROVIDER_ERROR = 'provider_error';

    public function __construct(
        public bool $confirmed,
        public string $reason,
    ) {}
}
