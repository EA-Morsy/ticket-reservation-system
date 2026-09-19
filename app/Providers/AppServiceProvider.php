<?php

namespace App\Providers;

use App\Contracts\PaymentGateway;
use App\Payments\PaymentGatewayFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the payment contract to the configured gateway factory.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, function (Application $app): PaymentGateway {
            return $app->make(PaymentGatewayFactory::class)->make();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
