<?php

use App\Domain\Payments\PaymentStatus;
use App\Models\Payment;
use App\Models\Reservation;
use App\Payments\Drivers\FakeGateway;
use App\Payments\DTO\CancelResult;
use App\Payments\DTO\PaymentEventType;
use App\Payments\Exceptions\InvalidWebhookSignatureException;
use App\Payments\Exceptions\ProviderRejectedException;
use App\Payments\Exceptions\ProviderTransientException;
use App\Payments\FakeWebhookSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->gateway = new FakeGateway;
    $this->reservation = Reservation::factory()->withSeats(2)->create()->refresh();
    $this->attempt = Payment::factory()->forReservation($this->reservation)->create();
});

function checkoutFor(Reservation $reservation): array
{
    return [$reservation, 'http://localhost:3000/r/'.$reservation->id, 'http://localhost:3000/r/'.$reservation->id.'/cancel'];
}

/**
 * Build a request the way the framework hands one to parseWebhook: the
 * signature is over the raw body, so the body string must be passed through
 * untouched rather than re-encoded.
 */
function webhookRequest(array $body, ?string $signature = null, bool $omitHeader = false): Request
{
    $signed = app(FakeWebhookSigner::class)->sign($body);

    $headers = $omitHeader ? [] : ['HTTP_X_FAKE_SIGNATURE' => $signature ?? $signed['headers']['X-Fake-Signature']];

    return Request::create(
        '/api/v1/webhooks/payments/fake',
        'POST',
        [],
        [],
        [],
        $headers + ['CONTENT_TYPE' => 'application/json'],
        $signed['body'],
    );
}

/*
|--------------------------------------------------------------------------
| createCheckout
|--------------------------------------------------------------------------
*/

it('opens a hosted session pointing at the configured base url', function () {
    [$reservation, $success, $cancel] = checkoutFor($this->reservation);

    $session = $this->gateway->createCheckout($this->attempt, $reservation, $success, $cancel);

    expect($session->providerRef)->toStartWith('fake_cs_')
        ->and($session->url)->toBe(config('payments.fake.checkout_base_url').'/'.$session->providerRef)
        ->and($session->expiresAt->isFuture())->toBeTrue()
        // 30 minutes mirrors Stripe's minimum session lifetime, which is why
        // a hold can lapse while a session is still payable.
        ->and($session->expiresAt->diffInMinutes(now(), absolute: true))->toBeLessThanOrEqual(31);
});

it('replays the same session for a second call on one attempt', function () {
    [$reservation, $success, $cancel] = checkoutFor($this->reservation);

    $first = $this->gateway->createCheckout($this->attempt, $reservation, $success, $cancel);
    $second = $this->gateway->createCheckout($this->attempt, $reservation, $success, $cancel);

    // This is the idempotency key doing its job: one attempt, one session,
    // however many times the call is retried.
    expect($second->providerRef)->toBe($first->providerRef)
        ->and($second->url)->toBe($first->url)
        // A replay reports the original deadline; it does not extend it.
        ->and($second->expiresAt->toIso8601String())->toBe($first->expiresAt->toIso8601String());
});

it('opens a distinct session for a different attempt', function () {
    [$reservation, $success, $cancel] = checkoutFor($this->reservation);

    $first = $this->gateway->createCheckout($this->attempt, $reservation, $success, $cancel);

    $this->attempt->transitionTo(PaymentStatus::Failed);
    $other = Payment::factory()->forReservation($this->reservation)->create();

    $second = $this->gateway->createCheckout($other, $reservation, $success, $cancel);

    expect($second->providerRef)->not->toBe($first->providerRef);
});

it('reports a definitive refusal as a rejection carrying its failure code', function () {
    [$reservation, $success, $cancel] = checkoutFor($this->reservation);

    FakeGateway::rejectNextCreate('amount_too_small');

    try {
        $this->gateway->createCheckout($this->attempt, $reservation, $success, $cancel);
        $this->fail('Expected ProviderRejectedException.');
    } catch (ProviderRejectedException $e) {
        expect($e->failureCode())->toBe('amount_too_small');
    }

    // Nothing was created, so the next call opens a fresh session.
    $session = $this->gateway->createCheckout($this->attempt, $reservation, $success, $cancel);
    expect($session->providerRef)->toStartWith('fake_cs_');
});

it('reports an unreachable provider as transient, having created nothing', function () {
    [$reservation, $success, $cancel] = checkoutFor($this->reservation);

    FakeGateway::failNextCreateTransient();

    expect(fn () => $this->gateway->createCheckout($this->attempt, $reservation, $success, $cancel))
        ->toThrow(ProviderTransientException::class);

    // The far end never opened a session, so cancelling finds nothing.
    expect($this->gateway->cancelCheckout($this->attempt)->reason)->toBe(CancelResult::NOT_FOUND);
});

it('recovers the session the provider created before the call timed out', function () {
    [$reservation, $success, $cancel] = checkoutFor($this->reservation);

    FakeGateway::timeoutNextCreate();

    expect(fn () => $this->gateway->createCheckout($this->attempt, $reservation, $success, $cancel))
        ->toThrow(ProviderTransientException::class);

    // The session exists at the provider even though we never saw the ref.
    // Replaying the same key must return it, not open a second one.
    $recovered = $this->gateway->createCheckout($this->attempt, $reservation, $success, $cancel);

    expect($recovered->providerRef)->toStartWith('fake_cs_')
        ->and(FakeGateway::session($recovered->providerRef))->not->toBeNull()
        ->and(FakeGateway::session($recovered->providerRef)['payment_id'])->toBe($this->attempt->id);
});

it('runs an onNextCreate callback after the session exists and before returning', function () {
    [$reservation, $success, $cancel] = checkoutFor($this->reservation);

    $refDuringCall = null;
    $ran = 0;

    FakeGateway::onNextCreate(function () use (&$refDuringCall, &$ran) {
        $ran++;
        // Stands in for something happening in the application while the
        // provider call is still in flight. The session must already exist by
        // now: the interleaving this hook stages depends on the provider
        // having created it before anything else gets a turn.
        $refDuringCall = $this->gateway->fetchStatus($this->attempt)->providerRef;
    });

    $session = $this->gateway->createCheckout($this->attempt, $reservation, $success, $cancel);

    expect($ran)->toBe(1)
        ->and($refDuringCall)->toBe($session->providerRef)
        ->and(FakeGateway::session($session->providerRef)['status'])->toBe('open');

    // Armed for one call only.
    $this->gateway->createCheckout($this->attempt, $reservation, $success, $cancel);
    expect($ran)->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The hooks themselves
|--------------------------------------------------------------------------
|
| Every test in the suite starts from a provider that has never seen us, so
| reset() disarming all six matters as much as any of them working.
|
*/

it('disarms every hook and forgets every session on reset', function () {
    [$reservation, $success, $cancel] = checkoutFor($this->reservation);

    $session = $this->gateway->createCheckout($this->attempt, $reservation, $success, $cancel);

    $ran = 0;
    FakeGateway::failNextCreateTransient();
    FakeGateway::timeoutNextCreate();
    FakeGateway::rejectNextCreate('amount_too_small');
    FakeGateway::failNextCancel();
    FakeGateway::failNextRefundTransient();
    FakeGateway::onNextCreate(function () use (&$ran) {
        $ran++;
    });

    FakeGateway::reset();

    // No session survives ...
    expect(FakeGateway::session($session->providerRef))->toBeNull();

    // ... and nothing is still armed: a create succeeds rather than throwing,
    // opens a brand-new session rather than replaying the forgotten one, and
    // runs no callback.
    $fresh = $this->gateway->createCheckout($this->attempt, $reservation, $success, $cancel);

    expect($fresh->providerRef)->not->toBe($session->providerRef)
        ->and($ran)->toBe(0)
        ->and($this->gateway->cancelCheckout($this->attempt)->confirmed)->toBeTrue()
        ->and($this->gateway->refund($this->attempt)->succeeded)->toBeTrue();
});

it('arms each create hook for exactly one call', function () {
    [$reservation, $success, $cancel] = checkoutFor($this->reservation);

    FakeGateway::failNextCreateTransient();
    expect(fn () => $this->gateway->createCheckout($this->attempt, $reservation, $success, $cancel))
        ->toThrow(ProviderTransientException::class);
    expect($this->gateway->createCheckout($this->attempt, $reservation, $success, $cancel)->providerRef)
        ->toStartWith('fake_cs_');

    FakeGateway::reset();

    FakeGateway::rejectNextCreate('unsupported_currency');
    expect(fn () => $this->gateway->createCheckout($this->attempt, $reservation, $success, $cancel))
        ->toThrow(ProviderRejectedException::class);
    expect($this->gateway->createCheckout($this->attempt, $reservation, $success, $cancel)->providerRef)
        ->toStartWith('fake_cs_');

    FakeGateway::reset();

    FakeGateway::timeoutNextCreate();
    expect(fn () => $this->gateway->createCheckout($this->attempt, $reservation, $success, $cancel))
        ->toThrow(ProviderTransientException::class);
    expect($this->gateway->createCheckout($this->attempt, $reservation, $success, $cancel)->providerRef)
        ->toStartWith('fake_cs_');
});

it('arms each cancel and refund hook for exactly one call', function () {
    [$reservation, $success, $cancel] = checkoutFor($this->reservation);

    $this->gateway->createCheckout($this->attempt, $reservation, $success, $cancel);

    FakeGateway::failNextCancel();
    expect($this->gateway->cancelCheckout($this->attempt)->reason)->toBe(CancelResult::PROVIDER_ERROR);
    expect($this->gateway->cancelCheckout($this->attempt)->confirmed)->toBeTrue();

    FakeGateway::failNextRefundTransient();
    expect($this->gateway->refund($this->attempt)->transient)->toBeTrue();
    expect($this->gateway->refund($this->attempt)->succeeded)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| cancelCheckout
|--------------------------------------------------------------------------
*/

it('expires an open session and confirms it can no longer complete', function () {
    [$reservation, $success, $cancel] = checkoutFor($this->reservation);

    $session = $this->gateway->createCheckout($this->attempt, $reservation, $success, $cancel);

    $result = $this->gateway->cancelCheckout($this->attempt);

    expect($result->confirmed)->toBeTrue()
        ->and($result->reason)->toBe(CancelResult::EXPIRED)
        ->and(FakeGateway::session($session->providerRef)['status'])->toBe('expired');
});

it('refuses to confirm a cancel once the session has completed', function () {
    [$reservation, $success, $cancel] = checkoutFor($this->reservation);

    $session = $this->gateway->createCheckout($this->attempt, $reservation, $success, $cancel);
    FakeGateway::completeSession($session->providerRef);

    $result = $this->gateway->cancelCheckout($this->attempt);

    // Not confirmed: the caller must leave the attempt initiated and let the
    // webhook settle it, rather than writing a cancelled it would have to undo.
    expect($result->confirmed)->toBeFalse()
        ->and($result->reason)->toBe(CancelResult::ALREADY_COMPLETED);
});

it('treats a session it has never seen as unable to complete', function () {
    $this->attempt->update(['provider_ref' => 'fake_cs_from_another_process']);

    $result = $this->gateway->cancelCheckout($this->attempt);

    expect($result->confirmed)->toBeTrue()
        ->and($result->reason)->toBe(CancelResult::NOT_FOUND);
});

it('reports a failed cancel as an unconfirmed result rather than throwing', function () {
    [$reservation, $success, $cancel] = checkoutFor($this->reservation);

    $this->gateway->createCheckout($this->attempt, $reservation, $success, $cancel);

    FakeGateway::failNextCancel();

    $result = $this->gateway->cancelCheckout($this->attempt);

    expect($result->confirmed)->toBeFalse()
        ->and($result->reason)->toBe(CancelResult::PROVIDER_ERROR);
});

/*
|--------------------------------------------------------------------------
| fetchStatus
|--------------------------------------------------------------------------
*/

it('reports an open session as nothing to act on', function () {
    [$reservation, $success, $cancel] = checkoutFor($this->reservation);

    $this->gateway->createCheckout($this->attempt, $reservation, $success, $cancel);

    $event = $this->gateway->fetchStatus($this->attempt);

    expect($event->type)->toBe(PaymentEventType::Unknown)
        ->and($event->amount)->toBeNull()
        ->and($event->currency)->toBeNull();
});

it('reports a completed session as succeeded, with the amount and currency', function () {
    [$reservation, $success, $cancel] = checkoutFor($this->reservation);

    $session = $this->gateway->createCheckout($this->attempt, $reservation, $success, $cancel);
    FakeGateway::completeSession($session->providerRef);

    $event = $this->gateway->fetchStatus($this->attempt);

    expect($event->type)->toBe(PaymentEventType::Succeeded)
        ->and($event->amount)->toBe((int) $this->attempt->amount)
        ->and($event->currency)->toBe($this->attempt->currency)
        ->and($event->paymentId)->toBe($this->attempt->id)
        ->and($event->providerRef)->toBe($session->providerRef);
});

it('reports a cancelled session as expired', function () {
    [$reservation, $success, $cancel] = checkoutFor($this->reservation);

    $this->gateway->createCheckout($this->attempt, $reservation, $success, $cancel);
    $this->gateway->cancelCheckout($this->attempt);

    expect($this->gateway->fetchStatus($this->attempt)->type)->toBe(PaymentEventType::Expired);
});

it('reports an attempt with no session at all as nothing to act on', function () {
    expect($this->gateway->fetchStatus($this->attempt)->type)->toBe(PaymentEventType::Unknown);
});

/*
|--------------------------------------------------------------------------
| parseWebhook
|--------------------------------------------------------------------------
*/

it('accepts a correctly signed body and normalises it', function () {
    $event = $this->gateway->parseWebhook(webhookRequest([
        'id' => 'evt_123',
        'type' => 'payment.succeeded',
        'data' => [
            'payment_id' => $this->attempt->id,
            'provider_ref' => 'fake_cs_abc',
            'amount' => 15000,
            'currency' => 'EGP',
        ],
    ]));

    expect($event->eventId)->toBe('evt_123')
        ->and($event->type)->toBe(PaymentEventType::Succeeded)
        ->and($event->paymentId)->toBe($this->attempt->id)
        ->and($event->providerRef)->toBe('fake_cs_abc')
        ->and($event->amount)->toBe(15000)
        ->and($event->currency)->toBe('EGP')
        ->and($event->raw['type'])->toBe('payment.succeeded');
});

it('rejects a tampered body', function () {
    $signed = app(FakeWebhookSigner::class)->sign([
        'id' => 'evt_123',
        'type' => 'payment.succeeded',
        'data' => ['payment_id' => $this->attempt->id, 'amount' => 15000, 'currency' => 'EGP'],
    ]);

    // Same signature, body edited after signing: exactly the attack the HMAC
    // over the raw body exists to stop.
    $tampered = str_replace('15000', '1', $signed['body']);

    $request = Request::create(
        '/api/v1/webhooks/payments/fake',
        'POST',
        [],
        [],
        [],
        ['HTTP_X_FAKE_SIGNATURE' => $signed['headers']['X-Fake-Signature'], 'CONTENT_TYPE' => 'application/json'],
        $tampered,
    );

    expect(fn () => $this->gateway->parseWebhook($request))
        ->toThrow(InvalidWebhookSignatureException::class);
});

it('rejects a body signed with the wrong secret', function () {
    $body = ['id' => 'evt_1', 'type' => 'payment.succeeded', 'data' => []];
    $json = json_encode($body);

    expect(fn () => $this->gateway->parseWebhook(
        webhookRequest($body, 'sha256='.hash_hmac('sha256', $json, 'not-the-secret'))
    ))->toThrow(InvalidWebhookSignatureException::class);
});

it('rejects a request with no signature header', function () {
    expect(fn () => $this->gateway->parseWebhook(
        webhookRequest(['id' => 'evt_1', 'type' => 'payment.succeeded', 'data' => []], omitHeader: true)
    ))->toThrow(InvalidWebhookSignatureException::class);
});

it('maps every event type it knows, and everything else to unknown', function () {
    $map = [
        'payment.succeeded' => PaymentEventType::Succeeded,
        'payment.failed' => PaymentEventType::Failed,
        'session.expired' => PaymentEventType::Expired,
        'customer.updated' => PaymentEventType::Unknown,
    ];

    foreach ($map as $providerType => $expected) {
        $event = $this->gateway->parseWebhook(webhookRequest([
            'id' => 'evt_'.$providerType,
            'type' => $providerType,
            'data' => ['payment_id' => $this->attempt->id],
        ]));

        expect($event->type)->toBe($expected, "{$providerType} should map to {$expected->name}");
    }
});

it('marks the session complete on a succeeded event, so a later cancel cannot confirm', function () {
    [$reservation, $success, $cancel] = checkoutFor($this->reservation);

    $session = $this->gateway->createCheckout($this->attempt, $reservation, $success, $cancel);

    $this->gateway->parseWebhook(webhookRequest([
        'id' => 'evt_paid',
        'type' => 'payment.succeeded',
        'data' => [
            'payment_id' => $this->attempt->id,
            'provider_ref' => $session->providerRef,
            'amount' => (int) $this->attempt->amount,
            'currency' => $this->attempt->currency,
        ],
    ]));

    expect(FakeGateway::session($session->providerRef)['status'])->toBe('complete');

    $result = $this->gateway->cancelCheckout($this->attempt);

    expect($result->confirmed)->toBeFalse()
        ->and($result->reason)->toBe(CancelResult::ALREADY_COMPLETED);
});

/*
|--------------------------------------------------------------------------
| refund
|--------------------------------------------------------------------------
*/

it('refunds with a provider reference', function () {
    $result = $this->gateway->refund($this->attempt);

    expect($result->succeeded)->toBeTrue()
        ->and($result->providerRefundRef)->toStartWith('fake_re_')
        ->and($result->transient)->toBeFalse();
});

it('reports a retryable refund failure as transient', function () {
    FakeGateway::failNextRefundTransient();

    $result = $this->gateway->refund($this->attempt);

    expect($result->succeeded)->toBeFalse()
        ->and($result->providerRefundRef)->toBeNull()
        ->and($result->transient)->toBeTrue();

    // One call only: the hook disarms itself.
    expect($this->gateway->refund($this->attempt)->succeeded)->toBeTrue();
});
