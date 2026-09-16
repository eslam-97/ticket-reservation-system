<?php

use App\Actions\Payments\StartCheckout;
use App\Actions\Reservations\CancelReservation;
use App\Actions\Reservations\CreateReservation;
use App\Domain\Reservations\ReservationStatus;
use App\Models\Event;
use App\Models\Seat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\LogManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Structured log context
|--------------------------------------------------------------------------
|
| Section 10: tracing one booking through the logs is the whole point, so
| every action sets Log::withContext() with the ids it knows as soon as it
| knows them. Monolog merges that context into each record, which is what
| makes "grep this reservation_id" work across an action, its transitions and
| its provider calls.
|
*/

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->event = Event::factory()->create(['starts_at' => now()->addYear()]);
    $this->seats = Seat::factory()->count(2)->for($this->event)->create();

    // A real Monolog handler, captured in memory: asserting on the merged
    // record is the only way to prove withContext() actually reached the log.
    $this->records = new Collection;

    Log::listen(function ($message) {
        $this->records->push($message);
    });
});

/**
 * @return list<array<string, mixed>>
 */
function recordsMentioning(string $key): array
{
    return test()->records
        ->filter(fn ($record) => array_key_exists($key, $record->context))
        ->map(fn ($record) => $record->context)
        ->values()
        ->all();
}

it('writes reservation_id and event_id when a reservation is created', function () {
    $reservation = app(CreateReservation::class)
        ->handle($this->user, $this->event, $this->seats->pluck('id')->all())
        ->reservation;

    $contexts = recordsMentioning('reservation_id');

    expect($contexts)->not->toBeEmpty();

    // At least one record carries both ids, which is what a reviewer greps.
    $traceable = array_filter(
        $contexts,
        fn (array $context) => ($context['reservation_id'] ?? null) === $reservation->id
            && ($context['event_id'] ?? null) === $this->event->id,
    );

    expect($traceable)->not->toBeEmpty(
        'expected a log record carrying both reservation_id and event_id; got: '
        .json_encode(array_slice($contexts, 0, 5))
    );
});

it('carries the reservation id through a status transition', function () {
    $reservation = app(CreateReservation::class)
        ->handle($this->user, $this->event, $this->seats->pluck('id')->all())
        ->reservation;

    $this->records = new Collection;

    $reservation->transitionTo(ReservationStatus::Confirmed);

    $contexts = recordsMentioning('reservation_id');

    expect($contexts)->not->toBeEmpty()
        ->and(array_column($contexts, 'reservation_id'))->toContain($reservation->id);
});

it('writes payment_id and reservation_id when a checkout opens', function () {
    $reservation = app(CreateReservation::class)
        ->handle($this->user, $this->event, $this->seats->pluck('id')->all())
        ->reservation;

    $this->records = new Collection;

    $result = app(StartCheckout::class)->handle($this->user, $reservation);

    $contexts = recordsMentioning('payment_id');

    expect($contexts)->not->toBeEmpty()
        ->and(array_column($contexts, 'payment_id'))->toContain($result->paymentId);

    // The reservation stays greppable alongside the attempt.
    expect(array_column(recordsMentioning('reservation_id'), 'reservation_id'))
        ->toContain($reservation->id);
});

it('writes the reservation id when a hold is cancelled', function () {
    $reservation = app(CreateReservation::class)
        ->handle($this->user, $this->event, $this->seats->pluck('id')->all())
        ->reservation;

    $this->records = new Collection;

    app(CancelReservation::class)->handle($this->user, $reservation);

    expect(array_column(recordsMentioning('reservation_id'), 'reservation_id'))
        ->toContain($reservation->id);
});

it('asserts against the real logger, not a swapped-in fake', function () {
    // Guards every test above: had Log been replaced with a spy, the context
    // assertions would pass without a single line ever being written.
    expect(Log::getFacadeRoot())->toBeInstanceOf(LogManager::class);
});
