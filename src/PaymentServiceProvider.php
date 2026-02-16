<?php

declare(strict_types=1);

namespace LaraPardakht;

use Illuminate\Support\ServiceProvider;

/**
 * Laravel service provider for the LaraPardakht payment package.
 *
 * Registers the PaymentManager as a singleton and publishes the config file.
 */
class PaymentServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/larapardakht.php',
            'larapardakht'
        );

        $this->app->singleton(PaymentManager::class, function ($app) {
            return new PaymentManager($app);
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/larapardakht.php' => config_path('larapardakht.php'),
            ], 'larapardakht-config');
        }
    }
}
