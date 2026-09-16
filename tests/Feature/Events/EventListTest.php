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

it('is public: no token required', function () {
    Event::factory()->create();

    $this->getJson('/api/v1/events')->assertOk();
});

it('returns each event with its shape', function () {
    $event = Event::factory()->create([
        'name' => 'Cairo Jazz Night',
        'currency' => 'EGP',
        'starts_at' => now()->addDays(3),
    ]);
    Seat::factory()->count(4)->for($event)->create();

    $response = $this->getJson('/api/v1/events')->assertOk();

    expect(array_keys($response->json('data.0')))->toEqualCanonicalizing([
        'id', 'name', 'starts_at', 'currency', 'seats_total', 'seats_available',
    ]);

    $response->assertJsonPath('data.0.id', $event->id)
        ->assertJsonPath('data.0.name', 'Cairo Jazz Night')
        ->assertJsonPath('data.0.currency', 'EGP')
        ->assertJsonPath('data.0.seats_total', 4)
        ->assertJsonPath('data.0.seats_available', 4)
        ->assertJsonPath('data.0.starts_at', $event->starts_at->utc()->toIso8601String());
});

it('returns starts_at as ISO 8601 in UTC', function () {
    Event::factory()->create(['starts_at' => '2026-12-01 18:30:00']);

    $startsAt = $this->getJson('/api/v1/events')->assertOk()->json('data.0.starts_at');

    expect($startsAt)->toBe('2026-12-01T18:30:00+00:00')
        ->and(fn () => new DateTimeImmutable($startsAt))->not->toThrow(Exception::class);
});

it('orders events by starts_at ascending', function () {
    $last = Event::factory()->create(['starts_at' => now()->addDays(30)]);
    $first = Event::factory()->create(['starts_at' => now()->addDays(3)]);
    $middle = Event::factory()->create(['starts_at' => now()->addDays(10)]);

    $ids = array_column($this->getJson('/api/v1/events')->assertOk()->json('data'), 'id');

    expect($ids)->toBe([$first->id, $middle->id, $last->id]);
});

it('paginates at twenty per page', function () {
    // Distinct start times so the ordering across pages is deterministic.
    collect(range(1, 25))->each(
        fn (int $n) => Event::factory()->create(['starts_at' => now()->addDays($n)])
    );

    $first = $this->getJson('/api/v1/events')->assertOk();

    expect($first->json('data'))->toHaveCount(20)
        ->and($first->json('meta.per_page'))->toBe(20)
        ->and($first->json('meta.total'))->toBe(25)
        ->and($first->json('meta.current_page'))->toBe(1);

    $second = $this->getJson('/api/v1/events?page=2')->assertOk();

    expect($second->json('data'))->toHaveCount(5)
        ->and($second->json('meta.current_page'))->toBe(2);

    // No event appears on both pages.
    $overlap = array_intersect(
        array_column($first->json('data'), 'id'),
        array_column($second->json('data'), 'id'),
    );

    expect($overlap)->toBeEmpty();
});

it('counts held and sold seats out of seats_available', function () {
    $event = Event::factory()->create();
    $seats = Seat::factory()->count(5)->for($event)->create();

    $held = Reservation::factory()->for($event)->create(['expires_at' => now()->addMinutes(30)]);
    ReservationSeat::factory()->create([
        'reservation_id' => $held->id,
        'seat_id' => $seats[0]->id,
        'unit_price' => $seats[0]->price,
    ]);

    $sold = Reservation::factory()->for($event)->withStatus(ReservationStatus::Confirmed)->create();
    ReservationSeat::factory()->create([
        'reservation_id' => $sold->id,
        'seat_id' => $seats[1]->id,
        'unit_price' => $seats[1]->price,
    ]);

    $this->getJson('/api/v1/events')->assertOk()
        ->assertJsonPath('data.0.seats_total', 5)
        ->assertJsonPath('data.0.seats_available', 3);
});

it('counts an expired hold as available before the sweep runs', function () {
    $event = Event::factory()->create(['starts_at' => now()->addYear()]);
    $seats = Seat::factory()->count(3)->for($event)->create();

    $reservation = Reservation::factory()->for($event)->create(['expires_at' => now()->addMinutes(30)]);
    ReservationSeat::factory()->create([
        'reservation_id' => $reservation->id,
        'seat_id' => $seats[0]->id,
        'unit_price' => $seats[0]->price,
    ]);

    $this->getJson('/api/v1/events')->assertOk()->assertJsonPath('data.0.seats_available', 2);

    travel(31)->minutes();

    // The count and the map agree because they run the same expression.
    $this->getJson('/api/v1/events')->assertOk()->assertJsonPath('data.0.seats_available', 3);
});

it('reports zero for an event with no seats', function () {
    Event::factory()->create();

    $this->getJson('/api/v1/events')->assertOk()
        ->assertJsonPath('data.0.seats_total', 0)
        ->assertJsonPath('data.0.seats_available', 0);
});

it('returns an empty page when there are no events', function () {
    $this->getJson('/api/v1/events')->assertOk()->assertJsonPath('data', []);
});

it('counts a page of twenty events without an N+1', function () {
    collect(range(1, 20))->each(function (int $n): void {
        $event = Event::factory()->create(['starts_at' => now()->addDays($n)]);
        Seat::factory()->count(3)->for($event)->create();
    });

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->getJson('/api/v1/events')->assertOk();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // Pagination count, pagination select, and one counts query for all 20.
    expect($queries)->toHaveCount(3, 'expected no per-event query, got: '
        .collect($queries)->pluck('query')->implode(' ;; '));
});
