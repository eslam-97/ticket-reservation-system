<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Payments\FakeWebhookSigner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Fire a signed fake-provider webhook at this application.
 *
 * The reviewer's stand-in for clicking "pay" on a hosted page. It signs the
 * body exactly as FakeGateway verifies it and posts it over real HTTP, so the
 * request goes through the same route, middleware, signature check and
 * transaction as a genuine provider callback — there is no shortcut into the
 * handler.
 *
 * Amount and currency default to the attempt's own values; overriding them is
 * how a mismatched payment gets simulated.
 */
class FakeWebhook extends Command
{
    protected $signature = 'payments:fake-webhook
                            {payment_id : The ULID of the payment attempt}
                            {--type=succeeded : succeeded, failed or expired}
                            {--amount= : Minor units; defaults to the attempt amount}
                            {--currency= : Defaults to the attempt currency}';

    protected $description = 'Post a signed fake payment webhook to this application';

    private const TYPES = [
        'succeeded' => 'payment.succeeded',
        'failed' => 'payment.failed',
        'expired' => 'session.expired',
    ];

    public function handle(FakeWebhookSigner $signer): int
    {
        $type = (string) $this->option('type');

        if (! isset(self::TYPES[$type])) {
            $this->error("Unknown --type [{$type}]. Use one of: ".implode(', ', array_keys(self::TYPES)).'.');

            return self::INVALID;
        }

        $attempt = Payment::query()->find($this->argument('payment_id'));

        if (! $attempt instanceof Payment) {
            $this->error("No payment attempt [{$this->argument('payment_id')}].");

            return self::FAILURE;
        }

        $signed = $signer->sign([
            'id' => 'evt_'.Str::ulid(),
            'type' => self::TYPES[$type],
            'data' => [
                'payment_id' => (string) $attempt->getKey(),
                'provider_ref' => $attempt->provider_ref,
                'amount' => (int) ($this->option('amount') ?? $attempt->amount),
                'currency' => (string) ($this->option('currency') ?? $attempt->currency),
            ],
        ]);

        $url = rtrim((string) config('app.url'), '/').'/api/v1/webhooks/payments/fake';

        $this->line('Equivalent curl:');
        $this->newLine();
        $this->line(sprintf(
            "curl -i -X POST %s \\\n  -H 'Content-Type: application/json' \\\n  -H 'X-Fake-Signature: %s' \\\n  -d '%s'",
            $url,
            $signed['headers']['X-Fake-Signature'],
            $signed['body'],
        ));
        $this->newLine();

        $response = Http::withHeaders($signed['headers'] + ['Content-Type' => 'application/json'])
            ->withBody($signed['body'], 'application/json')
            ->post($url);

        $this->line("Response: HTTP {$response->status()}");

        if ($response->body() !== '') {
            $this->line($response->body());
        }

        // 204 is the only success the webhook route ever returns.
        return $response->status() === 204 ? self::SUCCESS : self::FAILURE;
    }
}
