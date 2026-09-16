<?php

namespace App\Actions\Payments;

use Carbon\CarbonInterface;

final readonly class CheckoutResult
{
    public function __construct(
        public string $paymentId,
        public string $checkoutUrl,
        public string $providerRef,
        public CarbonInterface $sessionExpiresAt,
    ) {}
}
