<?php

namespace App\Payments\DTO;

/**
 * A provider event, normalised.
 *
 * `paymentId` is our own ULID, read back from the session metadata: it is
 * written before the provider call, so it is present even when the provider's
 * own reference never reached us. `providerRef` is the provider's id, which
 * may need backfilling onto the attempt.
 */
final readonly class PaymentEvent
{
    /**
     * @param  array<string, mixed>  $raw  the provider payload, stored for audit
     */
    public function __construct(
        public string $eventId,
        public PaymentEventType $type,
        public ?string $paymentId,
        public ?string $providerRef,
        public ?int $amount,
        public ?string $currency,
        public array $raw,
    ) {}
}
