<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\ReservationSeat;
use App\Models\Seat;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /** Seats per row, and the rows priced at the higher tier. */
    private const SEATS_PER_ROW = 20;

    private const PREMIUM_ROWS = ['A', 'B', 'C', 'D', 'E'];

    private const PREMIUM_PRICE = 50_000;

    private const STANDARD_PRICE = 25_000;

    /**
     * Idempotent: `make up` seeds on every run, so the demo data is rebuilt
     * from scratch rather than appended to.
     */
    public function run(): void
    {
        $this->truncateDemoData();

        $user = User::query()->firstOrCreate(
            ['email' => 'demo@example.com'],
            ['name' => 'Demo User', 'password' => Hash::make('password')],
        );

        // Three events at different distances, so a reviewer can see a normal
        // 30-minute hold and (by seeding closer) a clamped one.
        $plan = [
            ['Cairo Jazz Night', now()->addDays(3), 10],   // rows A–J: 200 seats
            ['Alexandria Film Gala', now()->addDays(10), 15],  // rows A–O: 300 seats
            ['Giza Symphony Open Air', now()->addDays(30), 20],  // rows A–T: 400 seats
        ];

        foreach ($plan as [$name, $startsAt, $rows]) {
            $event = Event::query()->create([
                'name' => $name,
                'starts_at' => $startsAt,
                'currency' => 'EGP',
            ]);

            $this->seedSeats($event, $rows);
        }

        $this->command?->info(sprintf(
            'Seeded %d events, %d seats and the demo user %s (password: "password").',
            Event::query()->count(),
            Seat::query()->count(),
            $user->email,
        ));
    }

    /**
     * Rows A, B, C … each with SEATS_PER_ROW numbered seats: A1..A20, B1..B20.
     */
    private function seedSeats(Event $event, int $rows): void
    {
        $now = now();
        $seats = [];

        foreach (range(0, $rows - 1) as $offset) {
            $row = chr(ord('A') + $offset);
            $price = in_array($row, self::PREMIUM_ROWS, true)
                ? self::PREMIUM_PRICE
                : self::STANDARD_PRICE;

            foreach (range(1, self::SEATS_PER_ROW) as $seat) {
                $seats[] = [
                    'event_id' => $event->id,
                    'number' => $row.$seat,
                    'price' => $price,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($seats, 200) as $chunk) {
            Seat::query()->insert($chunk);
        }
    }

    /**
     * Wipe the demo domain data. Children first would still trip the foreign
     * keys on TRUNCATE, so the checks come off for the duration.
     */
    private function truncateDemoData(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([ReservationSeat::class, Payment::class, Reservation::class, Seat::class, Event::class, WebhookEvent::class] as $model) {
            DB::table((new $model)->getTable())->truncate();
        }

        Schema::enableForeignKeyConstraints();
    }
}
