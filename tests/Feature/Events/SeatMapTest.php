<?php

use App\Domain\Reservations\ReservationStatus;
use App\Models\Event;
use App\Models\Reservation;
use App\Models\ReservationSeat;
use App\Models\Seat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\travel;

uses(RefreshDatabase::class);

/**
 * Claim `$seat` for a reservation in the given state and return the map row.
 *
 * @return array{id: int, number: string, price: int, status: string}
 */
function seatRow(Event $event, Seat $seat): array
{
    $response = test()->getJson("/api/v1/events/{$event->id}/seats")->assertOk();

    return collect($response->json('data'))->firstWhere('id', $seat->id);
}

it('reports a seat with no claim as available', function () {
    $event = Event::factory()->create();
    $seat = Seat::factory()->for($event)->create();

    expect(seatRow($event, $seat)['status'])->toBe('available');
});

it('reports an active claim on a live pending hold as held', function () {
    $event = Event::factory()->create();
    $seat = Seat::factory()->for($event)->create();

    $reservation = Reservation::factory()->for($event)->create([
        'expires_at' => now()->addMinutes(30),
    ]);

    ReservationSeat::factory()->create([
        'reservation_id' => $reservation->id,
        'seat_id' => $seat->id,
        'unit_price' => $seat->price,
    ]);

    expect(seatRow($event, $seat)['status'])->toBe('held');
});

it('reports an active claim on a confirmed reservation as sold', function () {
    $event = Event::factory()->create();
    $seat = Seat::factory()->for($event)->create();

    $reservation = Reservation::factory()
        ->for($event)
        ->withStatus(ReservationStatus::Confirmed)
        ->create(['expires_at' => now()->addMinutes(30)]);

    ReservationSeat::factory()->create([
        'reservation_id' => $reservation->id,
        'seat_id' => $seat->id,
        'unit_price' => $seat->price,
    ]);

    expect(seatRow($event, $seat)['status'])->toBe('sold');
});

it('frees a seat the moment its hold expires, with no sweep run', function () {
    $event = Event::factory()->create(['starts_at' => now()->addYear()]);
    $seat = Seat::factory()->for($event)->create();

    $reservation = Reservation::factory()->for($event)->create([
        'expires_at' => now()->addMinutes(30),
    ]);

    ReservationSeat::factory()->create([
        'reservation_id' => $reservation->id,
        'seat_id' => $seat->id,
        'unit_price' => $seat->price,
    ]);

    expect(seatRow($event, $seat)['status'])->toBe('held');

    travel(31)->minutes();

    // No reservations:expire run, and no claim released: the stored status is
    // still 'pending' and the claim is still active. The map must not wait
    // for the sweep to catch up.
    expect($reservation->fresh()->status)->toBe(ReservationStatus::Pending)
        ->and(ReservationSeat::query()->active()->where('seat_id', $seat->id)->count())->toBe(1)
        ->and(seatRow($event, $seat)['status'])->toBe('available');
});

it('frees a seat whose claim has been released', function () {
    $event = Event::factory()->create();
    $seat = Seat::factory()->for($event)->create();

    $reservation = Reservation::factory()->for($event)->create([
        'expires_at' => now()->addMinutes(30),
    ]);

    $claim = ReservationSeat::factory()->create([
        'reservation_id' => $reservation->id,
        'seat_id' => $seat->id,
        'unit_price' => $seat->price,
    ]);

    expect(seatRow($event, $seat)['status'])->toBe('held');

    $claim->update(['released_at' => now()]);

    expect(seatRow($event, $seat)['status'])->toBe('available');
});

it('exposes nothing beyond id, number, price and status', function () {
    $event = Event::factory()->create();
    $seat = Seat::factory()->for($event)->create();

    $reservation = Reservation::factory()->for($event)->create([
        'expires_at' => now()->addMinutes(30),
    ]);

    ReservationSeat::factory()->create([
        'reservation_id' => $reservation->id,
        'seat_id' => $seat->id,
        'unit_price' => $seat->price,
    ]);

    $response = $this->getJson("/api/v1/events/{$event->id}/seats")->assertOk();

    expect(array_keys($response->json('data.0')))
        ->toEqualCanonicalizing(['id', 'number', 'price', 'status']);

    // Every row, not just the first: no key may ever identify the holder.
    foreach ($response->json('data') as $row) {
        expect(array_keys($row))->toEqualCanonicalizing(['id', 'number', 'price', 'status']);
    }

    // The reservation ULID is 26 characters, so its absence is meaningful in
    // a way a bare integer user id would not be.
    expect($response->getContent())
        ->not->toContain($reservation->id)
        ->not->toContain('reservation_id')
        ->not->toContain('user_id')
        ->not->toContain('expires_at')
        ->not->toContain('released_at');
});

it('returns every seat of the event, ordered by id and unpaginated', function () {
    $event = Event::factory()->create();
    Seat::factory()->count(25)->for($event)->create();

    // A different event's seats must not leak into this map.
    Seat::factory()->count(5)->create();

    $response = $this->getJson("/api/v1/events/{$event->id}/seats")->assertOk();

    $ids = array_column($response->json('data'), 'id');

    expect($ids)->toHaveCount(25)
        ->and($ids)->toBe(collect($ids)->sort()->values()->all())
        ->and($response->json('meta'))->toBeNull()
        ->and($response->json('links'))->toBeNull();
});

it('returns the 404 envelope for an unknown event', function () {
    $this->getJson('/api/v1/events/999999/seats')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');
});

it('builds a 400-seat map in at most three queries', function () {
    $event = Event::factory()->create();

    $now = now();
    $rows = collect(range(1, 400))->map(fn (int $n): array => [
        'event_id' => $event->id,
        'number' => 'S'.$n,
        'price' => 25_000,
        'created_at' => $now,
        'updated_at' => $now,
    ])->all();

    foreach (array_chunk($rows, 200) as $chunk) {
        Seat::query()->insert($chunk);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = $this->getJson("/api/v1/events/{$event->id}/seats")->assertOk();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($response->json('data'))->toHaveCount(400)
        ->and($queries)->toHaveCount(2, 'seat map should be one lookup plus one map query, got: '
            .collect($queries)->pluck('query')->implode(' ;; '));
});
