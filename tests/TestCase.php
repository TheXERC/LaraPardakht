<?php

declare(strict_types=1);

namespace LaraPardakht\Tests;

use LaraPardakht\PaymentServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Base test case for LaraPardakht package tests.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Get package providers.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PaymentServiceProvider::class,
        ];
    }

    /**
     * Get package aliases.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return [
            'Payment' => \LaraPardakht\Facades\Payment::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return void
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('larapardakht.default', 'zarinpal');

        $app['config']->set('larapardakht.drivers.zarinpal', [
            'merchant_id' => 'test-merchant-id-xxxx-xxxx-xxxxxxxxxxxx',
            'sandbox' => false,
            'description' => 'Test payment',
            'callback_url' => 'https://example.com/callback',
        ]);

        $app['config']->set('larapardakht.drivers.zibal', [
            'merchant' => 'test-zibal-merchant',
            'sandbox' => false,
            'description' => 'Test payment via Zibal',
            'callback_url' => 'https://example.com/callback',
        ]);

        $app['config']->set('larapardakht.map', [
            'zarinpal' => \LaraPardakht\Drivers\Zarinpal\ZarinpalGateway::class,
            'zibal' => \LaraPardakht\Drivers\Zibal\ZibalGateway::class,
        ]);
    }
}
