<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Payment Gateway
    |--------------------------------------------------------------------------
    |
    | This option controls the default payment gateway that will be used when
    | no specific gateway is specified. You can change this at runtime using
    | the via() method.
    |
    */

    'default' => env('PAYMENT_GATEWAY', 'zarinpal'),

    /*
    |--------------------------------------------------------------------------
    | Payment Gateway Drivers
    |--------------------------------------------------------------------------
    |
    | Here you may configure each payment gateway driver. Each driver has its
    | own set of configuration options. The sandbox flag enables test mode
    | for gateways that support it.
    |
    */

    'drivers' => [

        'zarinpal' => [
            'merchant_id' => env('ZARINPAL_MERCHANT_ID', ''),
            'sandbox' => env('ZARINPAL_SANDBOX', false),
            'description' => 'Payment via Zarinpal',
            'callback_url' => env('PAYMENT_CALLBACK_URL', ''),
        ],

        'zibal' => [
            'merchant' => env('ZIBAL_MERCHANT', ''),
            'sandbox' => env('ZIBAL_SANDBOX', false),
            'description' => 'Payment via Zibal',
            'callback_url' => env('PAYMENT_CALLBACK_URL', ''),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Gateway Driver Class Map
    |--------------------------------------------------------------------------
    |
    | Each driver name must be mapped to its corresponding gateway class.
    | When adding a new gateway, add a new entry here pointing to your
    | driver class that implements GatewayInterface.
    |
    */

    'map' => [
        'zarinpal' => \LaraPardakht\Drivers\Zarinpal\ZarinpalGateway::class,
        'zibal' => \LaraPardakht\Drivers\Zibal\ZibalGateway::class,
    ],

];
