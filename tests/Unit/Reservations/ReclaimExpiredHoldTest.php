<?php

use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\ReclaimExpiredHold;
use App\Actions\Reservations\ReclaimOutcome;
use App\Domain\Reservations\ReservationStatus;
use App\Exceptions\Domain\SeatsUnavailableException;
use App\Models\Event;
use App\Models\Reservation;
use App\Models\ReservationSeat;
use App\Models\Seat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\travel;

uses(RefreshDatabase::class);

/**
 * A reservation on `$event` in a given state, holding `$seat`.
 */
function holdOn(Event $event, Seat $seat, ReservationStatus $status, bool $lapsed = false): Reservation
{
    $reservation = Reservation::query()->create([
        'user_id' => User::factory()->create()->id,
        'event_id' => $event->id,
        'status' => $status->value,
        'total_amount' => $seat->price,
        'currency' => $event->currency,
        'expires_at' => $lapsed ? now()->subMinute() : now()->addMinutes(30),
    ]);

    ReservationSeat::query()->create([
        'reservation_id' => $reservation->id,
        'seat_id' => $seat->id,
        'unit_price' => $seat->price,
    ]);

    return $reservation;
}

/**
 * The routine's precondition is that the caller already holds the seat locks
 * and is inside a transaction, so every call here is wrapped the same way.
 */
function reclaim(string $reservationId): ReclaimOutcome
{
    return DB::transaction(fn (): ReclaimOutcome => app(ReclaimExpiredHold::class)->handle($reservationId));
}

/*
|--------------------------------------------------------------------------
| The four outcomes
|--------------------------------------------------------------------------
*/

it('expires a lapsed hold and releases its claims', function () {
    $event = Event::factory()->create(['starts_at' => now()->addWeek()]);
    $seat = Seat::factory()->for($event)->create();
    $hold = holdOn($event, $seat, ReservationStatus::Pending, lapsed: true);

    expect(reclaim($hold->id))->toBe(ReclaimOutcome::Expired);

    $hold->refresh();

    expect($hold->status)->toBe(ReservationStatus::Expired)
        ->and($hold->claims()->active()->count())->toBe(0)
        ->and($hold->claims()->sole()->released_at)->not->toBeNull();
});

it('releases stragglers from a hold that was already expired', function () {
    $event = Event::factory()->create(['starts_at' => now()->addWeek()]);
    $seat = Seat::factory()->for($event)->create();

    // Stored `expired` but still holding its seat: the inconsistency the
    // reserve path logs and then repairs.
    $hold = holdOn($event, $seat, ReservationStatus::Expired, lapsed: true);

    expect(reclaim($hold->id))->toBe(ReclaimOutcome::AlreadyReleased);

    $hold->refresh();

    expect($hold->status)->toBe(ReservationStatus::Expired)
        ->and($hold->claims()->active()->count())->toBe(0);
});

it('releases stragglers from a cancelled hold without resurrecting it', function () {
    $event = Event::factory()->create(['starts_at' => now()->addWeek()]);
    $seat = Seat::factory()->for($event)->create();
    $hold = holdOn($event, $seat, ReservationStatus::Cancelled);

    expect(reclaim($hold->id))->toBe(ReclaimOutcome::AlreadyReleased);

    $hold->refresh();

    // Cancellation is terminal: the status never moves off it.
    expect($hold->status)->toBe(ReservationStatus::Cancelled)
        ->and($hold->claims()->active()->count())->toBe(0);
});

it('leaves a live hold completely untouched', function () {
    $event = Event::factory()->create(['starts_at' => now()->addWeek()]);
    $seat = Seat::factory()->for($event)->create();
    $hold = holdOn($event, $seat, ReservationStatus::Pending);

    $claimTouchedAt = $hold->claims()->sole()->updated_at;
    $holdTouchedAt = $hold->updated_at;

    expect(reclaim($hold->id))->toBe(ReclaimOutcome::StillPending);

    $hold->refresh();
    $claim = $hold->claims()->sole();

    expect($hold->status)->toBe(ReservationStatus::Pending)
        ->and($claim->released_at)->toBeNull()
        // No write happened at all, not even a touch.
        ->and($hold->updated_at->equalTo($holdTouchedAt))->toBeTrue()
        ->and($claim->updated_at->equalTo($claimTouchedAt))->toBeTrue();
});

it('leaves a confirmed hold completely untouched', function () {
    $event = Event::factory()->create(['starts_at' => now()->addWeek()]);
    $seat = Seat::factory()->for($event)->create();

    // Confirmed, and its deadline has passed: a hold paid for late, which the
    // caller might otherwise mistake for reclaimable.
    $hold = holdOn($event, $seat, ReservationStatus::Confirmed, lapsed: true);

    $claimTouchedAt = $hold->claims()->sole()->updated_at;
    $holdTouchedAt = $hold->updated_at;

    expect(reclaim($hold->id))->toBe(ReclaimOutcome::Confirmed);

    $hold->refresh();
    $claim = $hold->claims()->sole();

    expect($hold->status)->toBe(ReservationStatus::Confirmed)
        ->and($claim->released_at)->toBeNull()
        ->and($hold->updated_at->equalTo($holdTouchedAt))->toBeTrue()
        ->and($claim->updated_at->equalTo($claimTouchedAt))->toBeTrue();
});

it('treats a reservation that is not there as nothing left to do', function () {
    expect(reclaim((string) Str::ulid()))->toBe(ReclaimOutcome::AlreadyReleased);
});

/*
|--------------------------------------------------------------------------
| How the reserve flow reads a Confirmed outcome
|--------------------------------------------------------------------------
|
| Reaching this branch for real needs a confirmLate to commit between the
| reserve flow's unlocked read and its row lock, which cannot be staged from
| one thread. Swapping the routine for one that reports Confirmed exercises
| the mapping deterministically; the control test below is what makes the
| result attributable, by proving the identical setup otherwise succeeds.
|
*/

it('reserves the seat normally when the lapsed hold really is reclaimable', function () {
    $event = Event::factory()->create(['starts_at' => now()->addWeek()]);
    $seat = Seat::factory()->for($event)->create();

    holdOn($event, $seat, ReservationStatus::Pending);

    travel(31)->minutes();

    $result = app(CreateReservation::class)->handle(User::factory()->create(), $event, [$seat->id]);

    expect($result->created)->toBeTrue()
        ->and($result->reservation->seatIds())->toBe([$seat->id]);
});

it('refuses the seat when the reclaim reports the hold confirmed under lock', function () {
    $event = Event::factory()->create(['starts_at' => now()->addWeek()]);
    $seat = Seat::factory()->for($event)->create();

    holdOn($event, $seat, ReservationStatus::Pending);

    travel(31)->minutes();

    // The hold reads as lapsed, so step 2 classifies it reclaimable and
    // raises no conflict of its own. The only thing that can refuse this
    // request now is the Confirmed outcome.
    app()->instance(ReclaimExpiredHold::class, new class extends ReclaimExpiredHold
    {
        public function handle(string $reservationId): ReclaimOutcome
        {
            return ReclaimOutcome::Confirmed;
        }
    });

    expect(fn () => app(CreateReservation::class)->handle(User::factory()->create(), $event, [$seat->id]))
        ->toThrow(
            SeatsUnavailableException::class,
            'Some of those seats are no longer available.',
        );

    // The losing request wrote nothing.
    expect(Reservation::query()->count())->toBe(1);
});

it('names the contested seats when a reclaim reports the hold confirmed', function () {
    $event = Event::factory()->create(['starts_at' => now()->addWeek()]);
    $seats = Seat::factory()->count(3)->for($event)->create();
    $contested = $seats[1];

    holdOn($event, $contested, ReservationStatus::Pending);

    travel(31)->minutes();

    app()->instance(ReclaimExpiredHold::class, new class extends ReclaimExpiredHold
    {
        public function handle(string $reservationId): ReclaimOutcome
        {
            return ReclaimOutcome::Confirmed;
        }
    });

    try {
        app(CreateReservation::class)->handle(
            User::factory()->create(),
            $event,
            $seats->pluck('id')->all(),
        );

        $this->fail('Expected SeatsUnavailableException.');
    } catch (SeatsUnavailableException $e) {
        // Only the seat the confirmed hold actually owns, not the whole request.
        expect($e->details())->toBe(['seat_ids' => [$contested->id]]);
    }
});
