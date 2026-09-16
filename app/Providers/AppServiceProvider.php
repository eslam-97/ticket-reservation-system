<?php

namespace App\Providers;

use App\Database\DeadlocksOnlyErrorDetector;
use App\Models\Reservation;
use App\Policies\ReservationPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Retrying a transaction means a deadlock and nothing else. Laravel's
        // stock detector would also retry a lock-wait timeout, which the
        // design forbids: it doubles a request that already waited its full
        // timeout, and it stops the sweep skipping a contended row.
        $this->app->singleton(ConcurrencyErrorDetector::class, DeadlocksOnlyErrorDetector::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiters();

        // Registered explicitly rather than left to convention, so the
        // authorization surface is greppable from one place.
        Gate::policy(Reservation::class, ReservationPolicy::class);
    }

    /**
     * Design section 8: login 5/min per IP, reservations 10/min per user.
     */
    private function configureRateLimiters(): void
    {
        // Credential stuffing is an attack on an address we do not know yet,
        // so this one is keyed by IP rather than by user.
        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(5)->by($request->ip()));

        // Seat hoarding and provider spam are attacks by a known account.
        // These routes sit behind auth:sanctum, so the user is always there;
        // the IP fallback only exists so a missing user cannot lift the cap.
        RateLimiter::for('reservations', fn (Request $request): Limit => Limit::perMinute(10)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        // Webhooks are unauthenticated, so this is only a flood guard: it must
        // sit well above any provider's real redelivery rate, or we would
        // reject events we need and make the provider retry them forever.
        RateLimiter::for('webhooks', fn (Request $request): Limit => Limit::perMinute(120)->by($request->ip()));
    }
}
