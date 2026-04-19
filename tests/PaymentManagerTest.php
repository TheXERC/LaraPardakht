<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use LaraPardakht\Contracts\ReceiptInterface;
use LaraPardakht\DTOs\Invoice;
use LaraPardakht\DTOs\RedirectResponse;
use LaraPardakht\Events\PaymentPurchased;
use LaraPardakht\Events\PaymentVerified;
use LaraPardakht\Exceptions\InvalidConfigException;
use LaraPardakht\Exceptions\InvalidPaymentException;
use LaraPardakht\PaymentManager;

uses(\LaraPardakht\Tests\TestCase::class);

// ── Driver Resolution Tests ─────────────────────────────────

test('resolves default driver from config', function () {
    Http::fake([
        'payment.zarinpal.com/pg/v4/payment/request.json' => Http::response([
            'data' => [
                'code' => 100,
                'authority' => 'A00000000000000000000000000001234567',
            ],
            'errors' => [],
        ]),
    ]);

    $manager = app(PaymentManager::class);
    $invoice = (new Invoice())->amount(50000)->description('Test');

    $manager->purchase($invoice);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'zarinpal.com');
    });
});

test('switches driver via via() method', function () {
    Http::fake([
        'gateway.zibal.ir/v1/request' => Http::response([
            'trackId' => 12345,
            'result' => 100,
            'message' => 'success',
        ]),
    ]);

    $manager = app(PaymentManager::class);
    $invoice = (new Invoice())->amount(50000)->description('Test');

    $manager->via('zibal')->purchase($invoice);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'zibal.ir');
    });
});

test('throws exception for unknown driver', function () {
    $manager = app(PaymentManager::class);
    $invoice = (new Invoice())->amount(50000)->description('Test');

    $manager->via('nonexistent')->purchase($invoice);
})->throws(InvalidConfigException::class);

// ── Config Override Tests ───────────────────────────────────

test('overrides config at runtime', function () {
    Http::fake([
        'payment.zarinpal.com/pg/v4/payment/request.json' => Http::response([
            'data' => [
                'code' => 100,
                'authority' => 'A00000000000000000000000000001234567',
            ],
            'errors' => [],
        ]),
    ]);

    $manager = app(PaymentManager::class);
    $invoice = (new Invoice())->amount(50000)->description('Test');

    $manager->config('merchant_id', 'custom-merchant-id')->purchase($invoice);

    Http::assertSent(function ($request) {
        return $request['merchant_id'] === 'custom-merchant-id';
    });
});

test('overrides multiple configs at once', function () {
    Http::fake([
        'payment.zarinpal.com/pg/v4/payment/request.json' => Http::response([
            'data' => [
                'code' => 100,
                'authority' => 'A00000000000000000000000000001234567',
            ],
            'errors' => [],
        ]),
    ]);

    $manager = app(PaymentManager::class);
    $invoice = (new Invoice())->amount(50000)->description('Test');

    $manager->config([
        'merchant_id' => 'override-merchant',
        'description' => 'Override description',
    ])->purchase($invoice);

    Http::assertSent(function ($request) {
        return $request['merchant_id'] === 'override-merchant';
    });
});

// ── Callback URL Tests ──────────────────────────────────────

test('callbackUrl overrides invoice callback', function () {
    Http::fake([
        'payment.zarinpal.com/pg/v4/payment/request.json' => Http::response([
            'data' => [
                'code' => 100,
                'authority' => 'A00000000000000000000000000001234567',
            ],
            'errors' => [],
        ]),
    ]);

    $manager = app(PaymentManager::class);
    $invoice = (new Invoice())->amount(50000)->description('Test');

    $manager->callbackUrl('https://override.com/callback')->purchase($invoice);

    Http::assertSent(function ($request) {
        return $request['callback_url'] === 'https://override.com/callback';
    });
});

// ── Amount/TransactionId Shorthand Tests ────────────────────

test('amount and transactionId shortcuts work for verify', function () {
    Http::fake([
        'payment.zarinpal.com/pg/v4/payment/verify.json' => Http::response([
            'data' => [
                'code' => 100,
                'ref_id' => 777,
            ],
            'errors' => [],
        ]),
    ]);

    $manager = app(PaymentManager::class);

    $receipt = $manager->amount(50000)
        ->transactionId('A00000000000000000000000000001234567')
        ->verify();

    expect($receipt)->toBeInstanceOf(ReceiptInterface::class)
        ->and($receipt->getReferenceId())->toBe('777');
});

test('verify requires amount greater than zero', function () {
    $manager = app(PaymentManager::class);

    $manager->transactionId('A00000000000000000000000000001234567')->verify();
})->throws(InvalidPaymentException::class, 'Invoice amount is required and must be greater than zero before calling verify.');

// ── Purchase Callback Tests ─────────────────────────────────

test('purchase callback receives driver and transaction id', function () {
    Http::fake([
        'payment.zarinpal.com/pg/v4/payment/request.json' => Http::response([
            'data' => [
                'code' => 100,
                'authority' => 'A00000000000000000000000000001234567',
            ],
            'errors' => [],
        ]),
    ]);

    $receivedDriver = null;
    $receivedTransactionId = null;

    $manager = app(PaymentManager::class);
    $invoice = (new Invoice())->amount(50000)->description('Test');

    $manager->purchase($invoice, function ($driver, $transactionId) use (&$receivedDriver, &$receivedTransactionId) {
        $receivedDriver = $driver;
        $receivedTransactionId = $transactionId;
    });

    expect($receivedDriver)->toBe('zarinpal')
        ->and($receivedTransactionId)->toBe('A00000000000000000000000000001234567');
});

// ── Event Tests ─────────────────────────────────────────────

test('PaymentPurchased event is fired after purchase', function () {
    Event::fake([PaymentPurchased::class]);

    Http::fake([
        'payment.zarinpal.com/pg/v4/payment/request.json' => Http::response([
            'data' => [
                'code' => 100,
                'authority' => 'A00000000000000000000000000001234567',
            ],
            'errors' => [],
        ]),
    ]);

    $manager = app(PaymentManager::class);
    $invoice = (new Invoice())->amount(50000)->description('Test');

    $manager->purchase($invoice);

    Event::assertDispatched(PaymentPurchased::class, function ($event) {
        return $event->transactionId === 'A00000000000000000000000000001234567'
            && $event->driver === 'zarinpal';
    });
});

test('PaymentVerified event is fired after verify', function () {
    Event::fake([PaymentVerified::class]);

    Http::fake([
        'payment.zarinpal.com/pg/v4/payment/verify.json' => Http::response([
            'data' => [
                'code' => 100,
                'ref_id' => 201,
            ],
            'errors' => [],
        ]),
    ]);

    $manager = app(PaymentManager::class);
    $manager->amount(50000)
        ->transactionId('A00000000000000000000000000001234567')
        ->verify();

    Event::assertDispatched(PaymentVerified::class, function ($event) {
        return $event->receipt->getReferenceId() === '201'
            && $event->driver === 'zarinpal';
    });
});

test('PaymentVerified event is not fired when gateway reports already verified', function () {
    Event::fake([PaymentVerified::class]);

    Http::fake([
        'payment.zarinpal.com/pg/v4/payment/verify.json' => Http::response([
            'data' => [
                'code' => 101,
                'ref_id' => 201,
            ],
            'errors' => [],
        ]),
    ]);

    $manager = app(PaymentManager::class);
    $receipt = $manager->amount(50000)
        ->transactionId('A00000000000000000000000000001234567')
        ->verify();

    expect($receipt->getRawData()['already_verified'])->toBeTrue();

    Event::assertNotDispatched(PaymentVerified::class);
});

// ── Pay Chain Tests ─────────────────────────────────────────

test('purchase and pay chain works', function () {
    Http::fake([
        'payment.zarinpal.com/pg/v4/payment/request.json' => Http::response([
            'data' => [
                'code' => 100,
                'authority' => 'A00000000000000000000000000001234567',
            ],
            'errors' => [],
        ]),
    ]);

    $manager = app(PaymentManager::class);
    $invoice = (new Invoice())->amount(50000)->description('Test');

    $redirect = $manager->purchase($invoice)->pay();

    expect($redirect)->toBeInstanceOf(RedirectResponse::class)
        ->and($redirect->getUrl())->toContain('A00000000000000000000000000001234567');
});

test('purchase and pay chain works for idpay via runtime driver switch', function () {
    Http::fake([
        'api.idpay.ir/v1.1/payment' => Http::response([
            'id' => 'd2e353189823079e1e4181772cff5292',
            'link' => 'https://idpay.ir/p/ws-sandbox/d2e353189823079e1e4181772cff5292',
        ], 201),
    ]);

    $manager = app(PaymentManager::class);
    $invoice = (new Invoice())
        ->amount(50000)
        ->description('Test IDPay order')
        ->detail('order_id', 'ORD-9001');

    $redirect = $manager->via('idpay')->purchase($invoice)->pay();

    expect($redirect)->toBeInstanceOf(RedirectResponse::class)
        ->and($redirect->getUrl())
        ->toBe('https://idpay.ir/p/ws-sandbox/d2e353189823079e1e4181772cff5292');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.idpay.ir/v1.1/payment')
            && $request['order_id'] === 'ORD-9001'
            && $request['amount'] === 50000
            && $request['callback'] === 'https://example.com/callback'
            && $request->hasHeader('X-API-KEY', 'test-idpay-api-key');
    });
});

test('pay without transaction id throws InvalidPaymentException', function () {
    $manager = app(PaymentManager::class);

    $manager->amount(50000)->pay();
})->throws(InvalidPaymentException::class);

// ── Fresh State Tests ───────────────────────────────────────

test('fresh resets manager state', function () {
    Http::fake([
        'gateway.zibal.ir/v1/request' => Http::response([
            'trackId' => 55555,
            'result' => 100,
            'message' => 'success',
        ]),
        'payment.zarinpal.com/pg/v4/payment/request.json' => Http::response([
            'data' => [
                'code' => 100,
                'authority' => 'A00000000000000000000000000001234567',
            ],
            'errors' => [],
        ]),
    ]);

    $manager = app(PaymentManager::class);

    // First use with zibal
    $manager->via('zibal')->purchase((new Invoice())->amount(10000)->description('First'));

    // Reset and use default (zarinpal)
    $manager->fresh()->purchase((new Invoice())->amount(20000)->description('Second'));

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'gateway.zibal.ir/v1/request');
    });

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'payment.zarinpal.com/pg/v4/payment/request.json');
    });

    Http::assertSentCount(2);
});
