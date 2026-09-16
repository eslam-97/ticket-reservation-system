<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            // ULID in URLs: no enumeration of other people's reservations.
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('event_id')->constrained();
            $table->string('status', 24)->default('pending');
            // Frozen snapshot, never recomputed from current seat prices.
            $table->unsignedBigInteger('total_amount');
            $table->char('currency', 3);
            $table->timestamp('expires_at');
            $table->timestamps();

            // One pending reservation per user per event, enforced by the
            // database. MySQL has no partial unique index; a stored generated
            // column that collapses to NULL for every non-pending row gives the
            // same invariant, because unique indexes allow many NULLs.
            $table->string('pending_user_event', 64)
                ->nullable()
                ->storedAs("IF(status = 'pending', CONCAT(user_id, ':', event_id), NULL)");
            $table->unique('pending_user_event');

            // The sweep's candidate query: pending rows past their deadline.
            $table->index(['status', 'expires_at']);
            $table->index(['user_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
