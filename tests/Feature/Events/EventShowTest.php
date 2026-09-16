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
    $event = Event::factory()->create();

    $this->getJson("/api/v1/events/{$event->id}")->assertOk();
});

it('returns the same shape as the list', function () {
    $event = Event::factory()->create([
        'name' => 'Giza Symphony Open Air',
        'currency' => 'EGP',
        'starts_at' => now()->addDays(30),
    ]);
    Seat::factory()->count(6)->for($event)->create();

    $response = $this->getJson("/api/v1/events/{$event->id}")->assertOk();

    expect(array_keys($response->json('data')))->toEqualCanonicalizing([
        'id', 'name', 'starts_at', 'currency', 'seats_total', 'seats_available',
    ]);

    $response->assertJsonPath('data.id', $event->id)
        ->assertJsonPath('data.name', 'Giza Symphony Open Air')
        ->assertJsonPath('data.currency', 'EGP')
        ->assertJsonPath('data.starts_at', $event->starts_at->utc()->toIso8601String())
        ->assertJsonPath('data.seats_total', 6)
        ->assertJsonPath('data.seats_available', 6);
});

it('agrees with the seat map on what is available', function () {
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

    $this->getJson("/api/v1/events/{$event->id}")->assertOk()
        ->assertJsonPath('data.seats_total', 5)
        ->assertJsonPath('data.seats_available', 3);

    $map = collect($this->getJson("/api/v1/events/{$event->id}/seats")->assertOk()->json('data'));

    expect($map->where('status', 'available')->count())->toBe(3)
        ->and($map->where('status', 'held')->count())->toBe(1)
        ->and($map->where('status', 'sold')->count())->toBe(1);
});

it('counts an expired hold as available before the sweep runs', function () {
    $event = Event::factory()->create(['starts_at' => now()->addYear()]);
    $seats = Seat::factory()->count(2)->for($event)->create();

    $reservation = Reservation::factory()->for($event)->create(['expires_at' => now()->addMinutes(30)]);
    ReservationSeat::factory()->create([
        'reservation_id' => $reservation->id,
        'seat_id' => $seats[0]->id,
        'unit_price' => $seats[0]->price,
    ]);

    $this->getJson("/api/v1/events/{$event->id}")->assertOk()
        ->assertJsonPath('data.seats_available', 1);

    travel(31)->minutes();

    $this->getJson("/api/v1/events/{$event->id}")->assertOk()
        ->assertJsonPath('data.seats_available', 2);
});

it('returns the 404 envelope for an unknown event', function () {
    $this->getJson('/api/v1/events/999999')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found')
        ->assertJsonPath('error.message', 'Resource not found.')
        ->assertJsonStructure(['error' => ['code', 'message', 'details']]);
});

it('returns the 404 envelope for a non-numeric id', function () {
    $this->getJson('/api/v1/events/not-a-number')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found')
        ->assertJsonPath('error.message', 'Resource not found.');
});

it('resolves one event in two queries', function () {
    $event = Event::factory()->create();
    Seat::factory()->count(3)->for($event)->create();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->getJson("/api/v1/events/{$event->id}")->assertOk();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    // Route model binding, then one counts query.
    expect($queries)->toHaveCount(2, 'got: '.collect($queries)->pluck('query')->implode(' ;; '));
});
