<?php

namespace App\Payments\DTO;

/**
 * The outcome of a refund attempt.
 *
 * `transient` is the difference between "try again later" and "this will
 * never work": the scheduler backs off and retries the first, and escalates
 * the second.
 */
final readonly class RefundResult
{
    public function __construct(
        public bool $succeeded,
        public ?string $providerRefundRef,
        public bool $transient,
    ) {}
}
