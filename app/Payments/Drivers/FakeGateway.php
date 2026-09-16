<?php

namespace App\Payments\Drivers;

use App\Contracts\PaymentGateway;
use App\Models\Payment;
use App\Models\Reservation;
use App\Payments\DTO\CancelResult;
use App\Payments\DTO\CheckoutSession;
use App\Payments\DTO\PaymentEvent;
use App\Payments\DTO\PaymentEventType;
use App\Payments\DTO\RefundResult;
use App\Payments\Exceptions\InvalidWebhookSignatureException;
use App\Payments\Exceptions\ProviderRejectedException;
use App\Payments\Exceptions\ProviderTransientException;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * A provider that behaves like a real one without a network.
 *
 * This is the default driver, and it is fully functional: a reviewer can run
 * the entire flow — checkout, webhook, cancel, refund — with curl and no
 * provider account. Crucially it signs its webhooks with HMAC-SHA256 the way
 * a real provider does, and the verification path has no bypass flag, so
 * tests and the documented walkthrough exercise exactly the code that would
 * run against Stripe.
 *
 * Sessions live in a process-local static array. That is enough for one
 * request or one test, and deliberately not enough to pretend this is
 * durable: nothing in the system trusts the fake's memory, because the only
 * thing that changes state anywhere is a signed webhook.
 */
class FakeGateway implements PaymentGateway
{
    private const OPEN = 'open';

    private const COMPLETE = 'complete';

    private const EXPIRED = 'expired';

    /** Mimics Stripe's minimum Checkout Session lifetime. */
    private const SESSION_MINUTES = 30;

    /** @var array<string, array{status: string, payment_id: string, amount: int, currency: string, expires_at: string}> */
    private static array $sessions = [];

    /**
     * Attempt ULID to provider ref. This is the idempotency key emulated:
     * calling createCheckout twice for one attempt replays the first session
     * rather than opening a second, which is what a real provider does when
     * it sees `Idempotency-Key: payment:{id}` again.
     *
     * @var array<string, string>
     */
    private static array $refsByAttempt = [];

    private static ?string $nextCreateFailure = null;

    private static ?string $nextCreateRejection = null;

    private static bool $failNextCancel = false;

    private static bool $failNextRefundTransient = false;

    private static ?Closure $onNextCreate = null;

    public function createCheckout(Payment $attempt, Reservation $reservation, string $successUrl, string $cancelUrl): CheckoutSession
    {
        // A definitive refusal: the provider never created anything.
        if (self::$nextCreateRejection !== null) {
            $code = self::$nextCreateRejection;
            self::$nextCreateRejection = null;

            throw new ProviderRejectedException($code);
        }

        // A transient failure before the provider got as far as creating a
        // session: nothing exists at the far end.
        if (self::$nextCreateFailure === 'transient') {
            self::$nextCreateFailure = null;

            throw new ProviderTransientException('The fake provider could not be reached.');
        }

        $session = $this->openOrReplaySession($attempt);

        // The nastier shape: the provider *did* create the session and we
        // never heard back. The session is now on record, so a later call
        // with the same key recovers it instead of opening a second one.
        if (self::$nextCreateFailure === 'timeout') {
            self::$nextCreateFailure = null;

            throw new ProviderTransientException('Timed out waiting for the fake provider.');
        }

        // Lets a test run something while the "network call" is in flight,
        // which is how the checkout-versus-cancel interleaving is staged.
        if (self::$onNextCreate instanceof Closure) {
            $callback = self::$onNextCreate;
            self::$onNextCreate = null;
            $callback();
        }

        return $session;
    }

    public function cancelCheckout(Payment $attempt): CancelResult
    {
        // Best effort by contract: a failure here is a result, not an
        // exception, because leaving the attempt open is a valid outcome.
        if (self::$failNextCancel) {
            self::$failNextCancel = false;

            return new CancelResult(false, CancelResult::PROVIDER_ERROR);
        }

        $ref = $this->refFor($attempt);

        if ($ref === null || ! isset(self::$sessions[$ref])) {
            // Another process opened it, so it is not in this memory. It can
            // never complete without a signed webhook reaching us, so
            // reporting it as unable to complete is honest rather than
            // optimistic. See docs/DECISION_LOG.md.
            return new CancelResult(true, CancelResult::NOT_FOUND);
        }

        if (self::$sessions[$ref]['status'] === self::COMPLETE) {
            return new CancelResult(false, CancelResult::ALREADY_COMPLETED);
        }

        self::$sessions[$ref]['status'] = self::EXPIRED;

        return new CancelResult(true, CancelResult::EXPIRED);
    }

    public function parseWebhook(Request $request): PaymentEvent
    {
        $raw = $request->getContent();

        $expected = 'sha256='.hash_hmac('sha256', $raw, $this->secret());
        $provided = (string) $request->header('X-Fake-Signature', '');

        // Constant-time, and over the raw body: nothing is parsed until the
        // signature has been proved.
        if ($provided === '' || ! hash_equals($expected, $provided)) {
            throw new InvalidWebhookSignatureException('The fake webhook signature did not match.');
        }

        $payload = json_decode($raw, true);
        $payload = is_array($payload) ? $payload : [];
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        $type = match ($payload['type'] ?? null) {
            'payment.succeeded' => PaymentEventType::Succeeded,
            'payment.failed' => PaymentEventType::Failed,
            'session.expired' => PaymentEventType::Expired,
            default => PaymentEventType::Unknown,
        };

        $paymentId = isset($data['payment_id']) ? (string) $data['payment_id'] : null;
        $providerRef = isset($data['provider_ref'])
            ? (string) $data['provider_ref']
            : ($paymentId !== null ? (self::$refsByAttempt[$paymentId] ?? null) : null);

        // A completed session cannot later be cancelled, so record that here
        // the way a real provider's own books would.
        if ($type === PaymentEventType::Succeeded && $providerRef !== null && isset(self::$sessions[$providerRef])) {
            self::$sessions[$providerRef]['status'] = self::COMPLETE;
        }

        return new PaymentEvent(
            eventId: (string) ($payload['id'] ?? ''),
            type: $type,
            paymentId: $paymentId,
            providerRef: $providerRef,
            amount: isset($data['amount']) ? (int) $data['amount'] : null,
            currency: isset($data['currency']) ? (string) $data['currency'] : null,
            raw: $payload,
        );
    }

    public function fetchStatus(Payment $attempt): PaymentEvent
    {
        $ref = $this->refFor($attempt);
        $session = $ref !== null ? (self::$sessions[$ref] ?? null) : null;

        $type = match ($session['status'] ?? null) {
            self::COMPLETE => PaymentEventType::Succeeded,
            self::EXPIRED => PaymentEventType::Expired,
            // Still open, or unknown to this process: either way there is
            // nothing to act on yet.
            default => PaymentEventType::Unknown,
        };

        $succeeded = $type === PaymentEventType::Succeeded;

        return new PaymentEvent(
            eventId: 'fake_poll_'.Str::ulid(),
            type: $type,
            paymentId: (string) $attempt->getKey(),
            providerRef: $ref,
            amount: $succeeded ? (int) $session['amount'] : null,
            currency: $succeeded ? (string) $session['currency'] : null,
            raw: $session ?? [],
        );
    }

    public function refund(Payment $attempt): RefundResult
    {
        if (self::$failNextRefundTransient) {
            self::$failNextRefundTransient = false;

            return new RefundResult(false, null, transient: true);
        }

        return new RefundResult(true, 'fake_re_'.Str::ulid(), transient: false);
    }

    /*
    |--------------------------------------------------------------------------
    | Test hooks
    |--------------------------------------------------------------------------
    |
    | Each arms exactly one call and disarms itself, so a test says what it is
    | staging right where it stages it. reset() runs in Pest's beforeEach.
    |
    */

    public static function reset(): void
    {
        self::$sessions = [];
        self::$refsByAttempt = [];
        self::$nextCreateFailure = null;
        self::$nextCreateRejection = null;
        self::$failNextCancel = false;
        self::$failNextRefundTransient = false;
        self::$onNextCreate = null;
    }

    public static function failNextCreateTransient(): void
    {
        self::$nextCreateFailure = 'transient';
    }

    /**
     * The provider created the session, then the call timed out on our side.
     */
    public static function timeoutNextCreate(): void
    {
        self::$nextCreateFailure = 'timeout';
    }

    public static function rejectNextCreate(string $failureCode): void
    {
        self::$nextCreateRejection = $failureCode;
    }

    public static function failNextCancel(): void
    {
        self::$failNextCancel = true;
    }

    public static function failNextRefundTransient(): void
    {
        self::$failNextRefundTransient = true;
    }

    /**
     * Run `$callback` once, after the session exists but before createCheckout
     * returns — the application-side equivalent of "while the request was in
     * flight, something else happened".
     */
    public static function onNextCreate(callable $callback): void
    {
        self::$onNextCreate = $callback(...);
    }

    /**
     * Mark a session complete, as a hosted page would on payment.
     */
    public static function completeSession(string $providerRef): void
    {
        if (isset(self::$sessions[$providerRef])) {
            self::$sessions[$providerRef]['status'] = self::COMPLETE;
        }
    }

    /**
     * @return array{status: string, payment_id: string, amount: int, currency: string, expires_at: string}|null
     */
    public static function session(string $providerRef): ?array
    {
        return self::$sessions[$providerRef] ?? null;
    }

    private function openOrReplaySession(Payment $attempt): CheckoutSession
    {
        $attemptId = (string) $attempt->getKey();
        $ref = self::$refsByAttempt[$attemptId] ?? null;

        if ($ref === null) {
            $ref = 'fake_cs_'.Str::ulid();

            self::$refsByAttempt[$attemptId] = $ref;
            self::$sessions[$ref] = [
                'status' => self::OPEN,
                'payment_id' => $attemptId,
                'amount' => (int) $attempt->amount,
                'currency' => (string) $attempt->currency,
                'expires_at' => CarbonImmutable::now()->addMinutes(self::SESSION_MINUTES)->toIso8601String(),
            ];
        }

        return new CheckoutSession(
            providerRef: $ref,
            url: rtrim((string) config('payments.fake.checkout_base_url'), '/').'/'.$ref,
            // Replays report the original deadline, not a fresh one: an
            // idempotent call does not extend anything.
            expiresAt: CarbonImmutable::parse(self::$sessions[$ref]['expires_at']),
        );
    }

    private function refFor(Payment $attempt): ?string
    {
        return $attempt->provider_ref ?? (self::$refsByAttempt[(string) $attempt->getKey()] ?? null);
    }

    private function secret(): string
    {
        return (string) config('payments.fake.webhook_secret');
    }
}
