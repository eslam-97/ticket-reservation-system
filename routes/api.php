<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\EventSeatController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\ReservationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Everything lives under /api/v1 so the contract can evolve without breaking
| existing clients. The 'api' prefix comes from bootstrap/app.php.
|
*/

Route::prefix('v1')->group(function (): void {
    // Unauthenticated, and throttled per IP against credential stuffing.
    Route::post('auth/register', [AuthController::class, 'register'])
        ->middleware('throttle:login')
        ->name('auth.register');

    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('auth.login');

    // Browsing is public: events and their seat maps are read-only, and the
    // map deliberately says nothing about who holds a seat.
    Route::get('events', [EventController::class, 'index'])->name('events.index');

    Route::get('events/{event}', [EventController::class, 'show'])
        ->whereNumber('event')
        ->name('events.show');

    Route::get('events/{event}/seats', [EventSeatController::class, 'index'])
        ->whereNumber('event')
        ->name('events.seats.index');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout'])
            ->name('auth.logout');

        // Starting a hold is the contended write path: throttled per user
        // against seat hoarding.
        Route::post('reservations', [ReservationController::class, 'store'])
            ->middleware('throttle:reservations')
            ->name('reservations.store');

        Route::get('reservations/{reservation}', [ReservationController::class, 'show'])
            ->whereUlid('reservation')
            ->name('reservations.show');

        Route::post('reservations/{reservation}/checkout', [ReservationController::class, 'checkout'])
            ->middleware('throttle:reservations')
            ->whereUlid('reservation')
            ->name('reservations.checkout');

        Route::delete('reservations/{reservation}', [ReservationController::class, 'destroy'])
            ->whereUlid('reservation')
            ->name('reservations.destroy');
    });

    // Outside auth entirely: the provider has no token. The signature over the
    // raw body is the whole of the security, and it is checked before the body
    // is parsed. Throttled per IP only so a flood cannot be used as a DoS.
    Route::post('webhooks/payments/{provider}', PaymentWebhookController::class)
        ->middleware('throttle:webhooks')
        ->name('webhooks.payments');
});
