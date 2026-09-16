<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Payments\PaymentManager;
use Illuminate\Support\ServiceProvider;

class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentManager::class);

        // Everything outside the payments namespace type-hints the contract
        // and gets whichever driver config('payments.driver') names.
        $this->app->bind(
            PaymentGateway::class,
            fn ($app): PaymentGateway => $app->make(PaymentManager::class)->driver(),
        );
    }
}
