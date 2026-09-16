<?php

use App\Payments\Drivers\StripeGateway;
use App\Payments\DTO\PaymentEventType;
use App\Payments\Exceptions\ProviderRejectedException;
use App\Payments\Exceptions\ProviderTransientException;
use Stripe\Event as StripeEvent;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\Exception\UnknownApiErrorException;

/*
|--------------------------------------------------------------------------
| Stripe driver
|--------------------------------------------------------------------------
|
| No network anywhere in this file. Everything here is translation: Stripe's
| exceptions into ours, and Stripe's event shapes into the DTOs the domain
| speaks. The API calls themselves are Stripe's to get right.
|
*/

beforeEach(function () {
    config([
        'payments.stripe.secret_key' => 'sk_test_unit',
        'payments.stripe.webhook_secret' => 'whsec_unit',
    ]);

    $this->gateway = new StripeGateway;
});

/**
 * Build the Stripe exception type a given HTTP status would produce.
 */
function stripeError(string $class, string $message, int $status, ?string $code = null): Throwable
{
    return $class::factory($message, $status, null, [
        'error' => array_filter(['type' => 'invalid_request_error', 'code' => $code]),
    ]);
}

/*
|--------------------------------------------------------------------------
| Exception mapping: transient is the client's problem, rejected the operator's
|--------------------------------------------------------------------------
*/

dataset('transient failures', [
    'connection error' => fn () => ApiConnectionException::factory('Could not connect to Stripe.'),
    'rate limited' => fn () => stripeError(RateLimitException::class, 'Too many requests.', 429, 'rate_limit'),
    'stripe 500' => fn () => stripeError(UnknownApiErrorException::class, 'Internal error.', 500),
    'stripe 502' => fn () => stripeError(UnknownApiErrorException::class, 'Bad gateway.', 502),
    'stripe 503' => fn () => stripeError(UnknownApiErrorException::class, 'Service unavailable.', 503),
    // Not a Stripe exception at all: the outcome is unknown, so treat it as
    // retryable rather than assume nothing happened.
    'socket timeout' => fn () => new RuntimeException('cURL operation timed out'),
]);

dataset('definitive rejections', [
    'invalid request' => [fn () => stripeError(InvalidRequestException::class, 'No such price.', 400, 'resource_missing'), 'resource_missing'],
    'amount too small' => [fn () => stripeError(InvalidRequestException::class, 'Amount must be at least 50.', 400, 'amount_too_small'), 'amount_too_small'],
    'unsupported currency' => [fn () => stripeError(InvalidRequestException::class, 'Currency not supported.', 400, 'currency_not_supported'), 'currency_not_supported'],
    'account restricted' => [fn () => stripeError(InvalidRequestException::class, 'Account cannot charge.', 403, 'account_invalid'), 'account_invalid'],
    'bad api key' => [fn () => stripeError(AuthenticationException::class, 'Invalid API key.', 401, 'api_key_invalid'), 'api_key_invalid'],
    'card declined' => [fn () => stripeError(CardException::class, 'Card declined.', 402, 'card_declined'), 'card_declined'],
    // A 4xx with no code still has to become something loggable.
    'coded nothing' => [fn () => stripeError(InvalidRequestException::class, 'Something.', 400, null), 'stripe_error'],
]);

it('maps a failure that may succeed on a retry to transient', function (Throwable $e) {
    expect($this->gateway->mapException($e))->toBeInstanceOf(ProviderTransientException::class);
})->with('transient failures');

it('maps a definitive refusal to a rejection carrying its code', function (Throwable $e, string $expectedCode) {
    $mapped = $this->gateway->mapException($e);

    expect($mapped)->toBeInstanceOf(ProviderRejectedException::class)
        // The code is what reaches the operator in details.failure_code, so
        // it is the part that must survive the translation.
        ->and($mapped->failureCode())->toBe($expectedCode);
})->with('definitive rejections');

it('keeps the original exception as the cause', function () {
    $original = stripeError(InvalidRequestException::class, 'No such price.', 400, 'resource_missing');

    expect($this->gateway->mapException($original)->getPrevious())->toBe($original);
});

/*
|--------------------------------------------------------------------------
| Webhook normalisation
|--------------------------------------------------------------------------
*/

/**
 * A Stripe event object, built the way the SDK builds one from a payload.
 */
function stripeEvent(string $type, array $session = [], string $id = 'evt_test_1'): StripeEvent
{
    return StripeEvent::constructFrom([
        'id' => $id,
        'object' => 'event',
        'type' => $type,
        'data' => ['object' => array_merge([
            'id' => 'cs_test_123',
            'object' => 'checkout.session',
        ], $session)],
    ]);
}

it('maps a paid checkout session to a success', function () {
    $event = $this->gateway->normaliseEvent(stripeEvent('checkout.session.completed', [
        'payment_status' => 'paid',
        'amount_total' => 100000,
        'currency' => 'egp',
        'metadata' => ['payment_id' => '01m2abcdefghijklmnopqrstuv'],
    ]));

    expect($event->type)->toBe(PaymentEventType::Succeeded)
        ->and($event->eventId)->toBe('evt_test_1')
        ->and($event->providerRef)->toBe('cs_test_123')
        ->and($event->paymentId)->toBe('01m2abcdefghijklmnopqrstuv')
        ->and($event->amount)->toBe(100000)
        // Stripe speaks lowercase; our snapshots are uppercase, and the
        // webhook compares the two for equality.
        ->and($event->currency)->toBe('EGP');
});

it('does not treat a completed but unpaid session as a success', function () {
    // An async payment method can complete the session while the money is
    // still in flight. Confirming here would sell a seat for nothing.
    $event = $this->gateway->normaliseEvent(stripeEvent('checkout.session.completed', [
        'payment_status' => 'unpaid',
        'amount_total' => 100000,
        'currency' => 'egp',
    ]));

    expect($event->type)->toBe(PaymentEventType::Unknown)
        ->and($event->amount)->toBeNull();
});

it('maps an async payment failure to a failure', function () {
    $event = $this->gateway->normaliseEvent(stripeEvent('checkout.session.async_payment_failed', [
        'metadata' => ['payment_id' => '01m2abcdefghijklmnopqrstuv'],
    ]));

    expect($event->type)->toBe(PaymentEventType::Failed)
        ->and($event->paymentId)->toBe('01m2abcdefghijklmnopqrstuv');
});

it('maps an expired session to an expiry', function () {
    $event = $this->gateway->normaliseEvent(stripeEvent('checkout.session.expired'));

    expect($event->type)->toBe(PaymentEventType::Expired)
        ->and($event->providerRef)->toBe('cs_test_123');
});

it('maps everything else to unknown without losing the payload', function () {
    $event = $this->gateway->normaliseEvent(stripeEvent('customer.subscription.updated'));

    expect($event->type)->toBe(PaymentEventType::Unknown)
        ->and($event->eventId)->toBe('evt_test_1')
        // Still recorded in full: webhook_events is an audit trail.
        ->and($event->raw['type'])->toBe('customer.subscription.updated');
});

it('falls back to client_reference_id when metadata is absent', function () {
    $event = $this->gateway->normaliseEvent(stripeEvent('checkout.session.completed', [
        'payment_status' => 'paid',
        'amount_total' => 5000,
        'currency' => 'egp',
        'client_reference_id' => '01m2zzzzzzzzzzzzzzzzzzzzzz',
    ]));

    expect($event->paymentId)->toBe('01m2zzzzzzzzzzzzzzzzzzzzzz');
});
