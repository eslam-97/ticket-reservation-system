<?php

namespace App\Payments;

use App\Contracts\PaymentGateway;
use App\Exceptions\Domain\NotFound;
use App\Payments\Drivers\FakeGateway;
use App\Payments\Drivers\StripeGateway;
use Illuminate\Support\Manager;
use InvalidArgumentException;

/**
 * Resolves the gateway the application should talk to.
 *
 * Business code depends on PaymentGateway and never on a driver, so swapping
 * providers is a config change. The webhook route is the one place that needs
 * a specific driver rather than the configured one, because the provider
 * arrives in the URL path.
 *
 * @method PaymentGateway driver(string|null $driver = null)
 */
class PaymentManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('payments.driver');
    }

    public function createFakeDriver(): PaymentGateway
    {
        return new FakeGateway;
    }

    /**
     * Never the default. It is reachable through driverFor() so a Stripe
     * webhook can be verified and applied even while config('payments.driver')
     * is still 'fake' — the provider is named in the URL path, not by us.
     */
    public function createStripeDriver(): PaymentGateway
    {
        return new StripeGateway;
    }

    /**
     * The gateway for a provider named in a request path.
     *
     * A provider we do not implement is a 404 rather than a 500: the path
     * simply does not address anything here.
     */
    public function driverFor(string $provider): PaymentGateway
    {
        try {
            return $this->driver($provider);
        } catch (InvalidArgumentException) {
            throw new NotFound(
                "Unknown payment provider [{$provider}].",
                ['provider' => $provider],
            );
        }
    }
}
