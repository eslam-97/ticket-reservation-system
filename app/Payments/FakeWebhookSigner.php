<?php

namespace App\Payments;

use JsonException;

/**
 * Signs a fake-provider webhook body the way FakeGateway verifies it.
 *
 * Used by tests and by the payments:fake-webhook artisan command. It exists
 * so the signature is produced in exactly one place: the HMAC is over the raw
 * body, so the caller must send back the very string returned here, not a
 * re-encoding of the same array.
 */
class FakeWebhookSigner
{
    /**
     * @param  array<string, mixed>  $body
     * @return array{body: string, headers: array{'X-Fake-Signature': string}}
     *
     * @throws JsonException
     */
    public function sign(array $body): array
    {
        $json = json_encode($body, JSON_THROW_ON_ERROR);

        return [
            'body' => $json,
            'headers' => [
                'X-Fake-Signature' => 'sha256='.hash_hmac('sha256', $json, (string) config('payments.fake.webhook_secret')),
            ],
        ];
    }
}
