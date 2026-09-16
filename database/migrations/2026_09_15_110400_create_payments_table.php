<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            // Written before the provider call, so it is always available to
            // resolve the attempt from webhook metadata.
            $table->ulid('id')->primary();
            $table->foreignUlid('reservation_id')->constrained();
            $table->string('provider', 32);
            $table->string('provider_ref', 191)->nullable()->index();
            // Identity fields: amount, currency and provider never change.
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->string('status', 24)->default('initiated');
            $table->string('failure_code', 64)->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamps();

            // One open attempt per reservation. Same generated-column trick:
            // every settled attempt collapses to NULL and stops competing.
            $table->char('open_reservation_id', 26)
                ->nullable()
                ->storedAs("IF(status = 'initiated', reservation_id, NULL)");
            $table->unique('open_reservation_id');

            // Refund backoff queue, and reconcile's "initiated for too long".
            $table->index(['status', 'next_retry_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
