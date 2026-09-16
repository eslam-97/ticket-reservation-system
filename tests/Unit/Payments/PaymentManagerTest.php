<?php

use App\Contracts\PaymentGateway;
use App\Exceptions\Domain\NotFound;
use App\Payments\Drivers\FakeGateway;
use App\Payments\Drivers\StripeGateway;
use App\Payments\PaymentManager;

it('resolves the driver named in config by default', function () {
    expect(config('payments.driver'))->toBe('fake')
        ->and(app(PaymentManager::class)->driver())->toBeInstanceOf(FakeGateway::class);
});

it('binds the contract to the configured driver', function () {
    // Business code type-hints PaymentGateway and never a driver class.
    expect(app(PaymentGateway::class))->toBeInstanceOf(FakeGateway::class);
});

it('hands back the same driver instance each time', function () {
    $manager = app(PaymentManager::class);

    // FakeGateway keeps sessions in statics, but a shared instance is what a
    // real driver holding an SDK client would want too.
    expect($manager->driver())->toBe($manager->driver());
});

it('resolves a driver named in a request path', function () {
    expect(app(PaymentManager::class)->driverFor('fake'))->toBeInstanceOf(FakeGateway::class);
});

it('treats an unknown provider in the path as a 404', function () {
    try {
        app(PaymentManager::class)->driverFor('nope');

        $this->fail('Expected NotFound.');
    } catch (NotFound $e) {
        // A provider we do not implement is not an internal error: that path
        // simply does not address anything here.
        expect($e->code())->toBe('not_found')
            ->and($e->status())->toBe(404)
            ->and($e->details())->toBe(['provider' => 'nope'])
            // Distinguishes this from the renderer's generic 404 arm, which
            // produces the same status and code.
            ->and($e->getMessage())->toBe('Unknown payment provider [nope].');
    }
});

it('offers the stripe driver by name without making it the default', function () {
    config(['payments.stripe.secret_key' => 'sk_test_unit']);

    // Reachable by path so a Stripe callback can be verified and applied,
    // while business code still gets whatever config names.
    expect(app(PaymentManager::class)->driverFor('stripe'))->toBeInstanceOf(StripeGateway::class)
        ->and(config('payments.driver'))->toBe('fake')
        ->and(app(PaymentManager::class)->driver())->toBeInstanceOf(FakeGateway::class);
});

it('resolves the stripe driver even with no secret key configured', function () {
    config(['payments.stripe.secret_key' => '']);

    // Verifying a webhook needs only the *webhook* secret, so a blank API key
    // must not stop the driver resolving. The SDK refuses an empty key
    // outright, which is why the driver passes null instead.
    expect(app(PaymentManager::class)->driverFor('stripe'))->toBeInstanceOf(StripeGateway::class);
});
