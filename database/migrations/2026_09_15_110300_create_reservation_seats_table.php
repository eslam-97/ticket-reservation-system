<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('reservation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seat_id')->constrained();
            // Price snapshot taken when the hold was created.
            $table->unsignedBigInteger('unit_price');
            // Released rows stay as history; only NULL means an active claim.
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            // One active claim per seat, enforced by the database. Released
            // rows collapse to NULL and a unique index allows many NULLs, so
            // the same seat can be claimed again after its hold is released.
            $table->unsignedBigInteger('active_seat_id')
                ->nullable()
                ->storedAs('IF(released_at IS NULL, seat_id, NULL)');
            $table->unique('active_seat_id');

            $table->index('seat_id');
            $table->index(['reservation_id', 'released_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_seats');
    }
};
