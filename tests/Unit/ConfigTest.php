<?php

it('exposes every payments setting under config(payments.*)', function () {
    expect(config('payments.driver'))->toBe('fake')
        ->and(config('payments.return_base_url'))->toBe('http://localhost:3000')
        ->and(config('payments.max_session_lifetime_minutes'))->toBe(1440)
        ->and(config('payments.http.connect_timeout'))->toBe(5)
        ->and(config('payments.http.timeout'))->toBe(10)
        ->and(config('payments.checkout_max_attempts'))->toBe(5)
        ->and(config('payments.reconcile_initiated_after_minutes'))->toBe(10)
        ->and(config('payments.refund_max_attempts'))->toBe(5)
        ->and(config('payments.fake.webhook_secret'))->toBe('change-me-fake-secret')
        ->and(config('payments.fake.checkout_base_url'))->toBe('http://localhost:8000/fake-checkout')
        ->and(config('payments.stripe'))->toHaveKeys(['secret_key', 'webhook_secret']);
});

it('exposes every reservation setting under config(reservations.*)', function () {
    expect(config('reservations.hold_minutes'))->toBe(30)
        ->and(config('reservations.max_seats'))->toBe(10);
});

it('expires sanctum tokens after 24 hours', function () {
    expect(config('sanctum.expiration'))->toBe(1440);
});

it('keeps a second mysql connection for holding locks in tests', function () {
    expect(config('database.connections.mysql_locker'))
        ->toBe(config('database.connections.mysql'));
});

it('never falls back to sqlite', function () {
    expect(config('database.default'))->toBe('mysql');
});
