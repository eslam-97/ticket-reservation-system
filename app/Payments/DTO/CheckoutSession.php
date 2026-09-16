<?php

namespace App\Payments\DTO;

use Carbon\CarbonImmutable;

/**
 * A hosted checkout session the user can be redirected to.
 */
final readonly class CheckoutSession
{
    public function __construct(
        public string $providerRef,
        public string $url,
        public CarbonImmutable $expiresAt,
    ) {}
}
