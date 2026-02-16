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

    Http::assertSentCount(2);
});
