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
use Illuminate\Http\Request;
use Stripe\Event as StripeEvent;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\HttpClient\CurlClient;
use Stripe\Stripe;
use Stripe\StripeClient;
use Stripe\Webhook;
use Throwable;

/**
 * Stripe Checkout, behind the same contract as the fake driver.
 *
 * This driver exists to prove the abstraction fits a real provider: it is
 * never the default, and nothing outside this file imports the Stripe SDK.
 * Everything Stripe-shaped — session ids, error codes, event names, the
 * signature scheme — is translated here into the DTOs the domain speaks.
 *
 * The two idempotency keys are the whole recovery story. `payment:{id}` makes
 * a retried createCheckout replay the original session instead of minting a
 * second one, and `refund:{id}` makes a retried refund after an unknown
 * outcome safe. Both are derived from our own ULID, which is written before
 * the call, so they exist even when nothing came back.
 */
class StripeGateway implements PaymentGateway
{
    /**
     * Stripe refuses a Checkout Session shorter than 30 minutes, which is
     * precisely why a hold can lapse while its session is still payable —
     * the case confirmLate exists for.
     */
    private const SESSION_MINUTES = 30;

    private readonly StripeClient $stripe;

    public function __construct()
    {
        $secretKey = (string) config('payments.stripe.secret_key');

        // Null rather than an empty string: the SDK refuses to construct with
        // a blank key, and verifying a webhook needs only the *webhook*
        // secret. A deployment that receives Stripe callbacks without making
        // Stripe API calls must still be able to resolve this driver; a call
        // that does need the key fails at the call, where it is diagnosable.
        $this->stripe = new StripeClient([
            'api_key' => $secretKey !== '' ? $secretKey : null,
        ]);

        // The SDK retries idempotently on connection errors and 409/429/5xx.
        // Safe precisely because every mutating call below carries a key.
        Stripe::setMaxNetworkRetries(2);

        $curl = CurlClient::instance();
        $curl->setConnectTimeout((int) config('payments.http.connect_timeout'));
        $curl->setTimeout((int) config('payments.http.timeout'));
    }

    public function createCheckout(Payment $attempt, Reservation $reservation, string $successUrl, string $cancelUrl): CheckoutSession
    {
        $seatCount = count($reservation->seatIds());

        try {
            $session = $this->stripe->checkout->sessions->create([
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                // One line item at the frozen total: per-seat lines would
                // have to be kept in step with the provider for no gain.
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => strtolower((string) $attempt->currency),
                        'unit_amount' => (int) $attempt->amount,
                        'product_data' => [
                            'name' => "{$seatCount} seats — {$reservation->event->name}",
                        ],
                    ],
                ]],
                // Our own identifier, carried by the session so the webhook
                // can resolve the attempt without the session id.
                'metadata' => [
                    'payment_id' => (string) $attempt->getKey(),
                    'reservation_id' => (string) $reservation->getKey(),
                ],
                'client_reference_id' => (string) $attempt->getKey(),
                'expires_at' => now()->addMinutes(self::SESSION_MINUTES)->timestamp,
            ], [
                'idempotency_key' => "payment:{$attempt->getKey()}",
            ]);
        } catch (Throwable $e) {
            throw $this->mapException($e);
        }

        return new CheckoutSession(
            providerRef: (string) $session->id,
            url: (string) $session->url,
            expiresAt: CarbonImmutable::createFromTimestampUTC((int) $session->expires_at),
        );
    }

    /**
     * Best effort by contract: every failure is a result, never an exception.
     *
     * Only `confirmed` licenses writing `cancelled` on the attempt, so the
     * distinction that matters here is "this session can no longer complete"
     * versus "it still might".
     */
    public function cancelCheckout(Payment $attempt): CancelResult
    {
        if ($attempt->provider_ref === null) {
            return new CancelResult(false, CancelResult::NOT_FOUND);
        }

        try {
            $this->stripe->checkout->sessions->expire($attempt->provider_ref);

            return new CancelResult(true, CancelResult::EXPIRED);
        } catch (InvalidRequestException $e) {
            return $this->mapCancelRefusal($e);
        } catch (Throwable) {
            // Unknown outcome: the session may still be live, so the attempt
            // stays initiated for the webhook or reconcile to settle.
            return new CancelResult(false, CancelResult::PROVIDER_ERROR);
        }
    }

    public function parseWebhook(Request $request): PaymentEvent
    {
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                (string) config('payments.stripe.webhook_secret'),
            );
        } catch (SignatureVerificationException $e) {
            throw new InvalidWebhookSignatureException('The Stripe webhook signature did not match.', 0, $e);
        }

        return $this->normaliseEvent($event);
    }

    /**
     * Normalise a verified Stripe event. Separated from the signature check so
     * it can be exercised without a signature.
     */
    public function normaliseEvent(StripeEvent $event): PaymentEvent
    {
        $session = $event->data->object ?? null;
        $raw = $event->toArray();

        $type = match ($event->type) {
            // `complete` alone is not enough: an async method can complete the
            // session while the money is still in flight.
            'checkout.session.completed' => ($session->payment_status ?? null) === 'paid'
                ? PaymentEventType::Succeeded
                : PaymentEventType::Unknown,
            'checkout.session.async_payment_failed' => PaymentEventType::Failed,
            'checkout.session.expired' => PaymentEventType::Expired,
            default => PaymentEventType::Unknown,
        };

        $succeeded = $type === PaymentEventType::Succeeded;

        return new PaymentEvent(
            eventId: (string) $event->id,
            type: $type,
            paymentId: $this->paymentIdOf($session),
            providerRef: isset($session->id) ? (string) $session->id : null,
            amount: $succeeded && isset($session->amount_total) ? (int) $session->amount_total : null,
            // Stripe speaks lowercase; our snapshots are ISO-4217 uppercase.
            currency: $succeeded && isset($session->currency) ? strtoupper((string) $session->currency) : null,
            raw: $raw,
        );
    }

    public function fetchStatus(Payment $attempt): PaymentEvent
    {
        if ($attempt->provider_ref === null) {
            // Nothing to poll. Reconcile expires a reference-less attempt on
            // age instead.
            return new PaymentEvent(
                eventId: 'fetch_none_'.$attempt->getKey(),
                type: PaymentEventType::Unknown,
                paymentId: (string) $attempt->getKey(),
                providerRef: null,
                amount: null,
                currency: null,
                raw: [],
            );
        }

        try {
            $session = $this->stripe->checkout->sessions->retrieve($attempt->provider_ref);
        } catch (Throwable $e) {
            throw $this->mapException($e);
        }

        $status = (string) ($session->status ?? 'open');

        $type = match (true) {
            $status === 'complete' && ($session->payment_status ?? null) === 'paid' => PaymentEventType::Succeeded,
            $status === 'expired' => PaymentEventType::Expired,
            // Still open, or complete but unpaid: nothing to act on yet.
            default => PaymentEventType::Unknown,
        };

        $succeeded = $type === PaymentEventType::Succeeded;

        return new PaymentEvent(
            eventId: 'fetch_'.$session->id.'_'.$status,
            type: $type,
            paymentId: $this->paymentIdOf($session) ?? (string) $attempt->getKey(),
            providerRef: (string) $session->id,
            amount: $succeeded && isset($session->amount_total) ? (int) $session->amount_total : null,
            currency: $succeeded && isset($session->currency) ? strtoupper((string) $session->currency) : null,
            raw: $session->toArray(),
        );
    }

    public function refund(Payment $attempt): RefundResult
    {
        if ($attempt->provider_ref === null) {
            return new RefundResult(false, null, transient: false);
        }

        try {
            $session = $this->stripe->checkout->sessions->retrieve($attempt->provider_ref);
            $intent = $session->payment_intent ?? null;

            if ($intent === null) {
                // Nothing was ever charged, so there is nothing to give back.
                return new RefundResult(false, null, transient: false);
            }

            $refund = $this->stripe->refunds->create([
                'payment_intent' => is_string($intent) ? $intent : $intent->id,
            ], [
                'idempotency_key' => "refund:{$attempt->getKey()}",
            ]);

            return new RefundResult(true, (string) $refund->id, transient: false);
        } catch (Throwable $e) {
            $mapped = $this->mapException($e);

            if ($mapped instanceof ProviderTransientException) {
                // Worth another go: the scheduler backs off and retries, and
                // the idempotency key keeps that safe.
                return new RefundResult(false, null, transient: true);
            }

            // Already refunded is a success from our side: the customer has
            // their money, and retrying would only fail forever.
            if ($e instanceof ApiErrorException && $this->isAlreadyRefunded($e)) {
                return new RefundResult(true, null, transient: false);
            }

            return new RefundResult(false, null, transient: false);
        }
    }

    /**
     * The one place Stripe's failures become ours.
     *
     * The split is by owner, not by severity: transient failures are the
     * client's to retry, definitive ones are the operator's to fix. An
     * unknown outcome is always treated as transient, because the safe
     * assumption is that the provider may have done the work.
     */
    public function mapException(Throwable $e): ProviderTransientException|ProviderRejectedException
    {
        if ($e instanceof ApiConnectionException || $e instanceof RateLimitException) {
            return new ProviderTransientException($e->getMessage(), 0, $e);
        }

        if ($e instanceof ApiErrorException) {
            // 5xx means Stripe had the problem, so the request may well
            // succeed next time.
            if ((int) $e->getHttpStatus() >= 500) {
                return new ProviderTransientException($e->getMessage(), 0, $e);
            }

            return new ProviderRejectedException(
                $e->getStripeCode() ?: ($e->getError()?->code ?: 'stripe_error'),
                $e->getMessage(),
                $e,
            );
        }

        // Anything else — a socket timeout surfacing as something the SDK did
        // not wrap — leaves the outcome unknown, so: transient.
        return new ProviderTransientException($e->getMessage(), 0, $e);
    }

    private function mapCancelRefusal(InvalidRequestException $e): CancelResult
    {
        $code = (string) ($e->getStripeCode() ?: $e->getError()?->code ?: '');
        $message = strtolower($e->getMessage());

        if ($code === 'resource_missing') {
            // Stripe has no such session, so it certainly cannot complete.
            return new CancelResult(true, CancelResult::NOT_FOUND);
        }

        // Stripe reports "you may only expire a session that is open" rather
        // than a dedicated code, so the message is what distinguishes a
        // session that has already been paid.
        if (str_contains($message, 'only expire a session') || str_contains($message, 'no longer open')
            || str_contains($message, 'already') || str_contains($message, 'complete')) {
            return new CancelResult(false, CancelResult::ALREADY_COMPLETED);
        }

        return new CancelResult(false, CancelResult::PROVIDER_ERROR);
    }

    private function isAlreadyRefunded(ApiErrorException $e): bool
    {
        $code = (string) ($e->getStripeCode() ?: $e->getError()?->code ?: '');

        return $code === 'charge_already_refunded'
            || str_contains(strtolower($e->getMessage()), 'already been refunded');
    }

    private function paymentIdOf(mixed $session): ?string
    {
        $id = $session->metadata->payment_id ?? $session->client_reference_id ?? null;

        return $id === null ? null : (string) $id;
    }
}
