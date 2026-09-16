<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Domain\InvalidSignatureException;
use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Payments\Exceptions\InvalidWebhookSignatureException;
use App\Payments\PaymentManager;
use App\Services\Payments\PaymentTransitions;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only thing in this system that changes state on a provider's say-so.
 *
 * Unauthenticated by necessity — the provider has no token — so the signature
 * over the raw body is the whole of the security, and it is verified before
 * anything is parsed out of that body. There is no bypass flag.
 *
 * Idempotency is a database constraint rather than code: the unique index on
 * (provider, event_id) is what makes a redelivery a no-op. webhook_events is
 * deliberately outside the seats -> reservations -> payments ->
 * reservation_seats order, because no other flow touches that table, so
 * inserting it first cannot form a cycle. A concurrent duplicate simply waits
 * on the index and then takes the duplicate branch.
 */
class PaymentWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentManager $payments,
        private readonly PaymentTransitions $transitions,
    ) {}

    public function __invoke(Request $request, string $provider): Response
    {
        // An unknown provider in the path addresses nothing here: 404.
        $gateway = $this->payments->driverFor($provider);

        try {
            $event = $gateway->parseWebhook($request);
        } catch (InvalidWebhookSignatureException $e) {
            // Logged without the body: an unverified payload is not something
            // to echo back or store.
            Log::warning('Rejected a webhook with an invalid signature.', [
                'provider' => $provider,
                'ip' => $request->ip(),
            ]);

            throw new InvalidSignatureException;
        }

        if ($event->eventId === '') {
            // Nothing to deduplicate on. Acknowledge so the provider stops
            // resending, but do not act on it or pollute the ledger.
            Log::warning('Webhook carried no event id; acknowledged without processing.', [
                'provider' => $provider,
            ]);

            return response()->noContent();
        }

        DB::transaction(function () use ($event, $provider): void {
            try {
                $record = WebhookEvent::query()->create([
                    'provider' => $provider,
                    'event_id' => $event->eventId,
                    'payload' => $event->raw,
                ]);
            } catch (UniqueConstraintViolationException) {
                Log::info('Duplicate webhook delivery ignored.', [
                    'provider' => $provider,
                    'event_id' => $event->eventId,
                ]);

                return;
            }

            $this->transitions->apply($event, 'webhook', $provider);

            $record->forceFill(['processed_at' => now()])->save();
        }, attempts: 3);

        // 204 for both a processed event and a duplicate: from the provider's
        // side they are the same answer. Anything that throws rolls the whole
        // transaction back, webhook_events row included, and becomes a 500 so
        // the provider retries.
        return response()->noContent();
    }
}
