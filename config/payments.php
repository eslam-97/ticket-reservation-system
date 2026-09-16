<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Active driver
    |--------------------------------------------------------------------------
    |
    | Resolved by App\Payments\PaymentManager. The domain never imports a
    | provider SDK: adding a provider is one driver class plus one value here.
    |
    */

    'driver' => env('PAYMENTS_DRIVER', 'fake'),

    /*
    |--------------------------------------------------------------------------
    | Hosted checkout return URLs
    |--------------------------------------------------------------------------
    |
    | Success and cancel URLs are built from this base plus the reservation ID.
    | The client never supplies a URL (open-redirect protection).
    |
    */

    'return_base_url' => env('PAYMENTS_RETURN_BASE_URL', 'http://localhost:3000'),

    /*
    |--------------------------------------------------------------------------
    | Maximum provider session lifetime
    |--------------------------------------------------------------------------
    |
    | payments:reconcile marks a ref-less `initiated` attempt `expired` once it
    | is older than this, because no provider session can still be live.
    |
    */

    'max_session_lifetime_minutes' => (int) env('PAYMENTS_MAX_SESSION_LIFETIME_MINUTES', 1440),

    /*
    |--------------------------------------------------------------------------
    | Provider HTTP timeouts
    |--------------------------------------------------------------------------
    |
    | Never spent inside a database transaction (design section 4).
    |
    */

    'http' => [
        'connect_timeout' => (int) env('PAYMENTS_HTTP_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('PAYMENTS_HTTP_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Checkout attempts
    |--------------------------------------------------------------------------
    |
    | Checkout stays retryable until the hold expires, capped at this many
    | payment attempts per reservation.
    |
    */

    'checkout_max_attempts' => (int) env('CHECKOUT_MAX_ATTEMPTS', 5),

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    |
    | payments:reconcile polls `initiated` attempts older than this via
    | fetchStatus(), covering webhooks that never arrived.
    |
    */

    'reconcile_initiated_after_minutes' => (int) env('RECONCILE_INITIATED_AFTER_MINUTES', 10),

    /*
    |--------------------------------------------------------------------------
    | Refunds
    |--------------------------------------------------------------------------
    |
    | `refund_pending` attempts are retried with backoff; after this many
    | failures the scheduler logs at alert level.
    |
    */

    'refund_max_attempts' => (int) env('REFUND_MAX_ATTEMPTS', 5),

    /*
    |--------------------------------------------------------------------------
    | Per-driver settings
    |--------------------------------------------------------------------------
    |
    | Keyed by driver name, so PaymentManager can read config("payments.{$driver}")
    | for whichever gateway is in play.
    |
    */

    'fake' => [
        'webhook_secret' => env('FAKE_WEBHOOK_SECRET', 'change-me-fake-secret'),
        'checkout_base_url' => env('FAKE_CHECKOUT_BASE_URL', 'http://localhost:8000/fake-checkout'),
    ],

    'stripe' => [
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

];
