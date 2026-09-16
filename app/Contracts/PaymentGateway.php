<?php

namespace App\Contracts;

use App\Models\Payment;
use App\Models\Reservation;
use App\Payments\DTO\CancelResult;
use App\Payments\DTO\CheckoutSession;
use App\Payments\DTO\PaymentEvent;
use App\Payments\DTO\RefundResult;
use App\Payments\Exceptions\InvalidWebhookSignatureException;
use App\Payments\Exceptions\ProviderRejectedException;
use App\Payments\Exceptions\ProviderTransientException;
use Illuminate\Http\Request;

/**
 * Everything this system needs from a payment provider.
 *
 * The domain never imports a provider SDK: adding Paymob or Fawry later is one
 * class implementing this interface plus one config value. Implementations
 * talk to a provider and translate; they hold no business rules, take no
 * locks, and never touch a reservation's status.
 *
 * No method here may be called inside a database transaction. Every flow is
 * shaped T1 (commit) -> provider call -> T2 (commit), so that a ten-second
 * timeout never holds a row lock.
 */
interface PaymentGateway
{
    /**
     * Open a hosted checkout session for one payment attempt.
     *
     * Implementations MUST send `Idempotency-Key: payment:{$attempt->id}`
     * (Stripe: the `idempotency_key` request option) and MUST send identical
     * parameters on every call for the same attempt. That is what makes the
     * recovery path safe: when the server times out after the provider has
     * already created a session, the next call replays the original rather
     * than minting a second one.
     *
     * The attempt's ULID MUST travel in the session metadata under
     * `payment_id` (and `client_reference_id` on Stripe), because the webhook
     * resolves the attempt by that and not by the provider's session id —
     * our identifier is written before the call, so it always exists.
     *
     * @throws ProviderTransientException timeout, connection error, 429, 5xx,
     *                                    or any outcome we cannot determine
     * @throws ProviderRejectedException a definitive 4xx
     */
    public function createCheckout(Payment $attempt, Reservation $reservation, string $successUrl, string $cancelUrl): CheckoutSession;

    /**
     * Best-effort: stop the hosted session from completing.
     *
     * Never throws ProviderRejectedException. Every failure — including the
     * session having already completed — comes back as a CancelResult, because
     * the caller's next move depends on the reason and an unconfirmed cancel
     * is a normal outcome rather than an error.
     */
    public function cancelCheckout(Payment $attempt): CancelResult;

    /**
     * Verify and normalise an incoming webhook.
     *
     * The signature MUST be checked over the RAW request body before anything
     * is parsed out of it.
     *
     * @throws InvalidWebhookSignatureException
     */
    public function parseWebhook(Request $request): PaymentEvent;

    /**
     * Ask the provider what became of an attempt, by its provider_ref.
     *
     * Returns a PaymentEvent of type Unknown while the session is still open.
     * This is the self-healing path for webhooks that never arrived.
     *
     * @throws ProviderTransientException
     */
    public function fetchStatus(Payment $attempt): PaymentEvent;

    /**
     * Refund a succeeded attempt.
     *
     * Implementations MUST send `Idempotency-Key: refund:{$attempt->id}`, so a
     * retry after an unknown outcome cannot refund the customer twice.
     */
    public function refund(Payment $attempt): RefundResult;
}
