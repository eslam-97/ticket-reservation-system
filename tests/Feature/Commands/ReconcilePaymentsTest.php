<?php

use App\Actions\Reservations\CancelReservation;
use App\Actions\Reservations\CreateReservation;
use App\Domain\Payments\PaymentStatus;
use App\Domain\Reservations\ReservationStatus;
use App\Models\Event;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\User;
use App\Payments\Drivers\FakeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\artisan;
use function Pest\Laravel\travel;

uses(RefreshDatabase::class);

/**
 * A hold with a real checkout session behind it.
 *
 * @return array{0: Reservation, 1: Payment, 2: User}
 */
function holdWithCheckout(int $seats = 1): array
{
    $event = Event::factory()->create(['starts_at' => now()->addYear()]);
    $seatModels = Seat::factory()->count($seats)->for($event)->create();
    $user = User::factory()->create();

    $reservation = app(CreateReservation::class)
        ->handle($user, $event, $seatModels->pluck('id')->all())
        ->reservation;

    test()->actingAs($user)
        ->postJson("/api/v1/reservations/{$reservation->id}/checkout")
        ->assertOk();

    return [$reservation, Payment::query()->where('reservation_id', $reservation->id)->sole(), $user];
}

/**
 * An attempt whose checkout call timed out: initiated, no provider reference.
 */
function refLessAttempt(): Payment
{
    $event = Event::factory()->create(['starts_at' => now()->addYear()]);
    $seat = Seat::factory()->for($event)->create();
    $user = User::factory()->create();

    $reservation = app(CreateReservation::class)->handle($user, $event, [$seat->id])->reservation;

    FakeGateway::timeoutNextCreate();

    test()->actingAs($user)
        ->postJson("/api/v1/reservations/{$reservation->id}/checkout")
        ->assertStatus(503);

    return Payment::query()->where('reservation_id', $reservation->id)->sole();
}

/*
|--------------------------------------------------------------------------
| Pass A — the webhook never arrived, so ask the provider
|--------------------------------------------------------------------------
*/

it('settles a stale attempt the provider says was paid', function () {
    [$reservation, $attempt] = holdWithCheckout();

    // The user paid on the hosted page, but the callback never reached us.
    FakeGateway::completeSession($attempt->provider_ref);

    // Past the poll cutoff, but still inside the 30-minute hold.
    travel((int) config('payments.reconcile_initiated_after_minutes') + 1)->minutes();

    artisan('payments:reconcile')
        ->expectsOutputToContain('pass A (poll stale attempts): settled 1')
        ->assertSuccessful();

    expect($attempt->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($attempt->processed_at)->not->toBeNull()
        // Settled through the same guards the webhook uses.
        ->and($reservation->refresh()->status)->toBe(ReservationStatus::Confirmed)
        ->and($reservation->claims()->active()->count())->toBe(1);
});

it('leaves a session that is still open', function () {
    [$reservation, $attempt] = holdWithCheckout();

    travel((int) config('payments.reconcile_initiated_after_minutes') + 1)->minutes();

    artisan('payments:reconcile')
        ->expectsOutputToContain('pass A (poll stale attempts): settled 0, still open 1')
        ->assertSuccessful();

    expect($attempt->refresh()->status)->toBe(PaymentStatus::Initiated)
        ->and($reservation->refresh()->status)->toBe(ReservationStatus::Pending);
});

it('does not poll an attempt that is not stale yet', function () {
    [, $attempt] = holdWithCheckout();

    FakeGateway::completeSession($attempt->provider_ref);

    // Too young: the webhook still has time to arrive on its own.
    artisan('payments:reconcile')
        ->expectsOutputToContain('pass A (poll stale attempts): settled 0, still open 0')
        ->assertSuccessful();

    expect($attempt->refresh()->status)->toBe(PaymentStatus::Initiated);
});

/*
|--------------------------------------------------------------------------
| Pass B — an attempt that never got a provider reference
|--------------------------------------------------------------------------
*/

it('expires a reference-less attempt older than any session could live', function () {
    $old = refLessAttempt();

    travel((int) config('payments.max_session_lifetime_minutes') + 1)->minutes();

    $young = refLessAttempt();

    artisan('payments:reconcile')
        ->expectsOutputToContain('pass B (expire ref-less attempts): expired 1')
        ->assertSuccessful();

    // Nothing at the provider can still pay the old one.
    expect($old->refresh()->status)->toBe(PaymentStatus::Expired)
        ->and($old->processed_at)->not->toBeNull()
        // The young one may yet be recovered by a replayed idempotency key.
        ->and($young->refresh()->status)->toBe(PaymentStatus::Initiated);
});

/*
|--------------------------------------------------------------------------
| Pass C — a live page for something nobody can buy
|--------------------------------------------------------------------------
*/

it('closes an open session whose reservation was cancelled', function () {
    [$reservation, $attempt, $user] = holdWithCheckout();

    // The provider refused to confirm the close during cancel, so the attempt
    // was left open for exactly this pass to pick up.
    FakeGateway::failNextCancel();
    app(CancelReservation::class)->handle($user, $reservation);

    expect($attempt->refresh()->status)->toBe(PaymentStatus::Initiated);

    artisan('payments:reconcile')
        ->expectsOutputToContain('pass C (close orphaned sessions): closed 1')
        ->assertSuccessful();

    expect($attempt->refresh()->status)->toBe(PaymentStatus::Cancelled)
        ->and(FakeGateway::session($attempt->provider_ref)['status'])->toBe('expired')
        ->and($reservation->refresh()->status)->toBe(ReservationStatus::Cancelled);
});

it('closes an open session whose hold has lapsed', function () {
    [$reservation, $attempt] = holdWithCheckout();

    travel(31)->minutes();

    artisan('payments:reconcile')->assertSuccessful();

    expect($attempt->refresh()->status)->toBe(PaymentStatus::Cancelled)
        ->and(FakeGateway::session($attempt->provider_ref)['status'])->toBe('expired');
});

it('leaves an open session alone while its hold is live', function () {
    [, $attempt] = holdWithCheckout();

    artisan('payments:reconcile')
        ->expectsOutputToContain('pass C (close orphaned sessions): closed 0')
        ->assertSuccessful();

    expect($attempt->refresh()->status)->toBe(PaymentStatus::Initiated);
});

/*
|--------------------------------------------------------------------------
| Pass D — refunds, with backoff
|--------------------------------------------------------------------------
*/

/**
 * An attempt sitting in refund_pending, due for a retry now.
 */
function refundPendingAttempt(int $attempts = 0): Payment
{
    [, $attempt] = holdWithCheckout();

    $attempt->transitionTo(PaymentStatus::Succeeded);
    $attempt->transitionTo(PaymentStatus::RefundPending, [
        'next_retry_at' => now(),
        'attempts' => $attempts,
    ]);

    return $attempt;
}

it('refunds a pending refund and marks it refunded', function () {
    $attempt = refundPendingAttempt();

    artisan('payments:reconcile')
        ->expectsOutputToContain('pass D (retry refunds): refunded 1')
        ->assertSuccessful();

    $attempt->refresh();

    expect($attempt->status)->toBe(PaymentStatus::Refunded)
        ->and($attempt->processed_at)->not->toBeNull()
        ->and($attempt->raw_payload['refund_ref'])->toStartWith('fake_re_');
});

it('backs off and stays pending when the refund fails transiently', function () {
    $attempt = refundPendingAttempt();

    FakeGateway::failNextRefundTransient();

    artisan('payments:reconcile')
        ->expectsOutputToContain('pass D (retry refunds): refunded 0, retrying 1')
        ->assertSuccessful();

    $attempt->refresh();

    expect($attempt->status)->toBe(PaymentStatus::RefundPending)
        ->and($attempt->attempts)->toBe(1)
        // 2 ** 1 minutes from now: not eligible again on the next run.
        ->and($attempt->next_retry_at->isFuture())->toBeTrue();

    // Proving the backoff actually holds it back.
    artisan('payments:reconcile')
        ->expectsOutputToContain('pass D (retry refunds): refunded 0, retrying 0')
        ->assertSuccessful();

    expect($attempt->refresh()->attempts)->toBe(1);
});

it('retries once the backoff has elapsed', function () {
    $attempt = refundPendingAttempt();

    FakeGateway::failNextRefundTransient();
    artisan('payments:reconcile')->assertSuccessful();

    expect($attempt->refresh()->status)->toBe(PaymentStatus::RefundPending);

    travel(5)->minutes();

    artisan('payments:reconcile')
        ->expectsOutputToContain('pass D (retry refunds): refunded 1')
        ->assertSuccessful();

    expect($attempt->refresh()->status)->toBe(PaymentStatus::Refunded);
});

it('stops retrying once the attempt cap is spent', function () {
    $max = (int) config('payments.refund_max_attempts');

    // One short of the cap, so this run spends the last one.
    $attempt = refundPendingAttempt(attempts: $max - 1);

    FakeGateway::failNextRefundTransient();

    artisan('payments:reconcile')->assertSuccessful();

    $attempt->refresh();

    expect($attempt->status)->toBe(PaymentStatus::RefundPending)
        ->and($attempt->attempts)->toBe($max);

    // Due again by the clock, but past the cap: a human has to look at it now.
    $attempt->forceFill(['next_retry_at' => now()->subMinute()])->save();

    artisan('payments:reconcile')
        ->expectsOutputToContain('pass D (retry refunds): refunded 0, retrying 0, failed 0')
        ->assertSuccessful();

    expect($attempt->refresh()->attempts)->toBe($max)
        ->and($attempt->status)->toBe(PaymentStatus::RefundPending);
});

it('does not touch a refund that is not due yet', function () {
    $attempt = refundPendingAttempt();
    $attempt->forceFill(['next_retry_at' => now()->addHour()])->save();

    artisan('payments:reconcile')
        ->expectsOutputToContain('pass D (retry refunds): refunded 0, retrying 0, failed 0')
        ->assertSuccessful();

    expect($attempt->refresh()->status)->toBe(PaymentStatus::RefundPending)
        ->and($attempt->attempts)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The whole command
|--------------------------------------------------------------------------
*/

it('runs every pass and is safe to run again', function () {
    artisan('payments:reconcile')
        ->expectsOutputToContain('pass A')
        ->expectsOutputToContain('pass B')
        ->expectsOutputToContain('pass C')
        ->expectsOutputToContain('pass D')
        ->assertSuccessful();
});

it('leaves nothing to do on a second run', function () {
    [$reservation, $attempt] = holdWithCheckout();

    FakeGateway::completeSession($attempt->provider_ref);
    travel((int) config('payments.reconcile_initiated_after_minutes') + 1)->minutes();

    artisan('payments:reconcile')->assertSuccessful();

    expect($attempt->refresh()->status)->toBe(PaymentStatus::Succeeded);

    $settledAt = $attempt->processed_at;

    artisan('payments:reconcile')
        ->expectsOutputToContain('pass A (poll stale attempts): settled 0')
        ->assertSuccessful();

    expect($attempt->refresh()->processed_at->equalTo($settledAt))->toBeTrue()
        ->and($reservation->refresh()->status)->toBe(ReservationStatus::Confirmed);
});
