<?php

use App\Domain\Payments\PaymentStatus;
use App\Domain\Reservations\ReservationStatus;
use App\Models\Event;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationSeat;
use App\Models\Seat;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Generated-column invariants
|--------------------------------------------------------------------------
|
| These three unique indexes are the second line of defence behind the lock
| protocol: if a bug ever lets two writers past the locks, the database
| refuses the write instead of silently double-selling. They only work
| against real MySQL, which is why the suite never touches SQLite.
|
*/

it('allows only one active claim per seat', function () {
    $event = Event::factory()->create();
    $seat = Seat::factory()->for($event)->create();

    // Two different users: a shared user would trip the *other* index first.
    $first = Reservation::factory()->for($event)->create();
    $second = Reservation::factory()->for($event)->create();

    $claim = ReservationSeat::query()->create([
        'reservation_id' => $first->id,
        'seat_id' => $seat->id,
        'unit_price' => $seat->price,
    ]);

    expect(fn () => ReservationSeat::query()->create([
        'reservation_id' => $second->id,
        'seat_id' => $seat->id,
        'unit_price' => $seat->price,
    ]))->toThrow(UniqueConstraintViolationException::class);

    // Releasing the first claim collapses active_seat_id to NULL, and a unique
    // index tolerates any number of NULLs, so the seat can be retaken.
    $claim->released_at = now();
    $claim->save();

    $retaken = ReservationSeat::query()->create([
        'reservation_id' => $second->id,
        'seat_id' => $seat->id,
        'unit_price' => $seat->price,
    ]);

    expect($retaken->exists)->toBeTrue()
        ->and(ReservationSeat::query()->where('seat_id', $seat->id)->count())->toBe(2)
        ->and(ReservationSeat::query()->active()->where('seat_id', $seat->id)->count())->toBe(1);
});

it('allows only one pending reservation per user per event', function () {
    $user = User::factory()->create();
    $event = Event::factory()->create();

    $first = Reservation::factory()->for($user)->for($event)->create();

    expect(fn () => Reservation::factory()->for($user)->for($event)->create())
        ->toThrow(UniqueConstraintViolationException::class);

    $first->transitionTo(ReservationStatus::Expired);

    $second = Reservation::factory()->for($user)->for($event)->create();

    expect($second->exists)->toBeTrue()
        ->and(Reservation::query()->where('user_id', $user->id)->count())->toBe(2);
});

it('allows only one initiated payment per reservation', function () {
    $reservation = Reservation::factory()->create();

    $first = Payment::factory()->forReservation($reservation)->create();

    expect($first->status)->toBe(PaymentStatus::Initiated);

    expect(fn () => Payment::factory()->forReservation($reservation)->create())
        ->toThrow(UniqueConstraintViolationException::class);

    // A settled attempt collapses to NULL and stops competing for the index.
    $first->transitionTo(PaymentStatus::Failed, ['failure_code' => 'card_declined']);

    $second = Payment::factory()->forReservation($reservation)->create();

    expect($second->exists)->toBeTrue()
        ->and(Payment::query()->where('reservation_id', $reservation->id)->count())->toBe(2);
});
