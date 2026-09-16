<?php

use App\Actions\Reservations\CreateReservation;
use App\Domain\Payments\PaymentStatus;
use App\Domain\Reservations\ReservationStatus;
use App\Exceptions\Domain\IllegalTransitionException;
use App\Models\Event;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationSeat;
use App\Models\Seat;
use App\Models\User;
use App\Payments\DTO\PaymentEvent;
use App\Payments\DTO\PaymentEventType;
use App\Services\Payments\PaymentTransitions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\travel;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->event = Event::factory()->create(['starts_at' => now()->addWeek()]);
    $this->seats = Seat::factory()->count(2)->for($this->event)->create();

    $this->reservation = app(CreateReservation::class)
        ->handle($this->user, $this->event, $this->seats->pluck('id')->all())
        ->reservation;

    $this->attempt = Payment::query()->create([
        'reservation_id' => $this->reservation->id,
        'provider' => 'fake',
        'provider_ref' => 'fake_cs_unit',
        'amount' => (int) $this->reservation->total_amount,
        'currency' => $this->reservation->currency,
        'status' => PaymentStatus::Initiated,
        'attempts' => 0,
    ]);
});

function event_(
    PaymentEventType $type,
    ?Payment $attempt = null,
    ?int $amount = null,
    ?string $currency = null,
    ?string $paymentId = null,
    ?string $providerRef = null,
): PaymentEvent {
    $attempt ??= test()->attempt;

    return new PaymentEvent(
        eventId: 'evt_'.Str::ulid(),
        type: $type,
        paymentId: $paymentId ?? (string) $attempt->id,
        providerRef: $providerRef ?? $attempt->provider_ref,
        amount: $amount ?? (int) $attempt->amount,
        currency: $currency ?? $attempt->currency,
        raw: ['unit' => true],
    );
}

/**
 * apply() does its own locking but expects the caller to own the transaction.
 *
 * The provider defaults to the one these attempts are created with; the
 * cross-provider cases live in ProviderBoundaryTest.
 */
function applyEvent(PaymentEvent $event, string $source = 'webhook', string $provider = 'fake'): void
{
    DB::transaction(fn () => app(PaymentTransitions::class)->apply($event, $source, $provider), attempts: 3);
}

/*
|--------------------------------------------------------------------------
| Events we do not act on
|--------------------------------------------------------------------------
*/

it('does nothing at all for an unknown event type', function () {
    $touchedAt = $this->attempt->updated_at;

    applyEvent(event_(PaymentEventType::Unknown));

    expect($this->attempt->refresh()->status)->toBe(PaymentStatus::Initiated)
        ->and($this->attempt->updated_at->equalTo($touchedAt))->toBeTrue()
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Pending);
});

it('does nothing for an event naming a payment that does not exist', function () {
    applyEvent(event_(PaymentEventType::Succeeded, paymentId: (string) Str::ulid(), providerRef: 'fake_cs_nope'));

    expect($this->attempt->refresh()->status)->toBe(PaymentStatus::Initiated)
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Pending);
});

it('falls back to the provider reference when the event carries no payment id', function () {
    applyEvent(event_(PaymentEventType::Succeeded, paymentId: null));

    expect($this->attempt->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Confirmed);
});

/*
|--------------------------------------------------------------------------
| Failure and expiry leave the hold alone
|--------------------------------------------------------------------------
*/

it('fails the attempt without touching the reservation', function () {
    applyEvent(event_(PaymentEventType::Failed));

    expect($this->attempt->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($this->attempt->failure_code)->toBe('payment_failed')
        ->and($this->attempt->processed_at)->not->toBeNull()
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Pending)
        ->and($this->reservation->claims()->active()->count())->toBe(2);
});

it('expires the attempt without touching the reservation', function () {
    applyEvent(event_(PaymentEventType::Expired));

    expect($this->attempt->refresh()->status)->toBe(PaymentStatus::Expired)
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Pending)
        ->and($this->reservation->claims()->active()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Money has to match
|--------------------------------------------------------------------------
*/

it('marks a wrong amount as mismatched and confirms nothing', function () {
    applyEvent(event_(PaymentEventType::Succeeded, amount: 1));

    expect($this->attempt->refresh()->status)->toBe(PaymentStatus::Mismatched)
        ->and($this->attempt->raw_payload['event'])->toBe(['unit' => true])
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Pending);
});

it('marks a wrong currency as mismatched and confirms nothing', function () {
    applyEvent(event_(PaymentEventType::Succeeded, currency: 'USD'));

    expect($this->attempt->refresh()->status)->toBe(PaymentStatus::Mismatched)
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Pending);
});

/*
|--------------------------------------------------------------------------
| Success, by what the reservation is at the time
|--------------------------------------------------------------------------
*/

it('confirms a pending reservation and leaves its claims alone', function () {
    applyEvent(event_(PaymentEventType::Succeeded));

    expect($this->attempt->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($this->attempt->processed_at)->not->toBeNull()
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Confirmed)
        ->and($this->reservation->claims()->active()->count())->toBe(2)
        ->and($this->reservation->claims()->count())->toBe(2);
});

it('confirms late when the hold lapsed but nobody took the seats', function () {
    travel(31)->minutes();

    applyEvent(event_(PaymentEventType::Succeeded));

    expect($this->reservation->refresh()->status)->toBe(ReservationStatus::Confirmed)
        ->and($this->reservation->claims()->active()->count())->toBe(2)
        // Its own claims were never released, so no duplicates were inserted.
        ->and($this->reservation->claims()->count())->toBe(2);
});

it('confirms late with fresh claims after the sweep released the old ones', function () {
    $prices = $this->reservation->claims()->pluck('unit_price', 'seat_id');

    travel(31)->minutes();

    $this->reservation->transitionTo(ReservationStatus::Expired);
    $this->reservation->claims()->update(['released_at' => now()]);

    applyEvent(event_(PaymentEventType::Succeeded));

    $this->reservation->refresh();

    expect($this->reservation->status)->toBe(ReservationStatus::Confirmed)
        ->and($this->reservation->claims()->active()->count())->toBe(2)
        ->and($this->reservation->claims()->whereNotNull('released_at')->count())->toBe(2);

    $this->reservation->claims()->whereNull('released_at')->get()->each(
        fn (ReservationSeat $claim) => expect((int) $claim->unit_price)->toBe((int) $prices[$claim->seat_id])
    );
});

it('refunds a late payment when another reservation took a seat', function () {
    travel(31)->minutes();

    app(CreateReservation::class)->handle(
        User::factory()->create(),
        $this->event,
        [$this->seats[0]->id],
    );

    applyEvent(event_(PaymentEventType::Succeeded));

    $this->attempt->refresh();

    expect($this->attempt->status)->toBe(PaymentStatus::RefundPending)
        ->and($this->attempt->next_retry_at)->not->toBeNull()
        ->and($this->attempt->attempts)->toBe(0)
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Expired);
});

it('refunds a payment that lands after cancellation', function () {
    $this->reservation->transitionTo(ReservationStatus::Cancelled);
    $this->reservation->claims()->update(['released_at' => now()]);

    applyEvent(event_(PaymentEventType::Succeeded));

    expect($this->attempt->refresh()->status)->toBe(PaymentStatus::RefundPending)
        // Cancellation is final; nothing is re-claimed.
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Cancelled)
        ->and($this->reservation->claims()->active()->count())->toBe(0);
});

it('refunds a second attempt that pays for an already-confirmed reservation', function () {
    $this->reservation->transitionTo(ReservationStatus::Confirmed);

    applyEvent(event_(PaymentEventType::Succeeded));

    expect($this->attempt->refresh()->status)->toBe(PaymentStatus::RefundPending)
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Confirmed);
});

/*
|--------------------------------------------------------------------------
| Terminal attempts, and backfilling
|--------------------------------------------------------------------------
*/

it('ignores any event for an attempt that is already terminal', function () {
    foreach ([PaymentStatus::Failed, PaymentStatus::Expired, PaymentStatus::Cancelled, PaymentStatus::Mismatched] as $terminal) {
        $attempt = Payment::query()->create([
            'reservation_id' => Reservation::factory()->create()->id,
            'provider' => 'fake',
            'provider_ref' => 'fake_cs_'.$terminal->value,
            'amount' => 1000,
            'currency' => 'EGP',
            'status' => PaymentStatus::Initiated,
        ]);

        $attempt->transitionTo($terminal);

        applyEvent(event_(PaymentEventType::Succeeded, attempt: $attempt, amount: 1000, currency: 'EGP'));

        expect($attempt->refresh()->status)->toBe($terminal, "{$terminal->value} must stay terminal");
    }
});

it('ignores a duplicate success for an attempt that already succeeded', function () {
    applyEvent(event_(PaymentEventType::Succeeded));

    expect($this->attempt->refresh()->status)->toBe(PaymentStatus::Succeeded);

    $processedAt = $this->attempt->processed_at;

    applyEvent(event_(PaymentEventType::Succeeded));

    expect($this->attempt->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($this->attempt->processed_at->equalTo($processedAt))->toBeTrue()
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Confirmed);
});

it('backfills a provider reference that was never stored', function () {
    $this->attempt->forceFill(['provider_ref' => null])->save();

    applyEvent(event_(PaymentEventType::Succeeded, providerRef: 'fake_cs_late'));

    expect($this->attempt->refresh()->provider_ref)->toBe('fake_cs_late')
        ->and($this->attempt->status)->toBe(PaymentStatus::Succeeded);
});

/*
|--------------------------------------------------------------------------
| The machine refuses what it must
|--------------------------------------------------------------------------
*/

it('throws rather than writing a transition the machine forbids', function () {
    $this->attempt->transitionTo(PaymentStatus::Succeeded);

    // succeeded -> failed is not an edge. Nothing in apply() reaches this,
    // because terminal and non-initiated attempts are filtered first; the
    // guard is what makes that filtering safe rather than merely tidy.
    expect(fn () => $this->attempt->transitionTo(PaymentStatus::Failed))
        ->toThrow(IllegalTransitionException::class);

    expect($this->attempt->refresh()->status)->toBe(PaymentStatus::Succeeded);
});

it('throws rather than resurrecting a cancelled reservation', function () {
    $this->reservation->transitionTo(ReservationStatus::Cancelled);

    expect(fn () => $this->reservation->transitionTo(ReservationStatus::Confirmed))
        ->toThrow(IllegalTransitionException::class);

    expect($this->reservation->refresh()->status)->toBe(ReservationStatus::Cancelled);
});

it('works the same from reconcile as from the webhook', function () {
    applyEvent(event_(PaymentEventType::Succeeded), source: 'reconcile');

    expect($this->attempt->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($this->reservation->refresh()->status)->toBe(ReservationStatus::Confirmed);
});
