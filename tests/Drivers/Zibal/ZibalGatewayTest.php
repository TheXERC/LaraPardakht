<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use LaraPardakht\Contracts\ReceiptInterface;
use LaraPardakht\Drivers\Zibal\ZibalGateway;
use LaraPardakht\DTOs\Invoice;
use LaraPardakht\DTOs\RedirectResponse;
use LaraPardakht\Exceptions\InvalidPaymentException;
use LaraPardakht\Exceptions\PurchaseFailedException;

uses(\LaraPardakht\Tests\TestCase::class);

function createZibalGateway(array $settingsOverride = []): ZibalGateway
{
    $settings = array_merge([
        'merchant' => 'test-zibal-merchant',
        'sandbox' => false,
        'description' => 'Test payment via Zibal',
        'callback_url' => 'https://example.com/callback',
    ], $settingsOverride);

    return new ZibalGateway($settings);
}

function createZibalInvoice(int $amount = 160000, string $description = 'Test Zibal order'): Invoice
{
    $invoice = new Invoice();
    $invoice->amount($amount)->description($description);

    return $invoice;
}

// ── Purchase Tests ──────────────────────────────────────────

test('purchase success returns trackId as transaction id', function () {
    Http::fake([
        'gateway.zibal.ir/v1/request' => Http::response([
            'trackId' => 15966442233311,
            'result' => 100,
            'message' => 'success',
        ]),
    ]);

    $gateway = createZibalGateway();
    $invoice = createZibalInvoice();
    $gateway->setInvoice($invoice);

    $transactionId = $gateway->purchase();

    expect($transactionId)->toBe('15966442233311');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'gateway.zibal.ir/v1/request')
            && $request['merchant'] === 'test-zibal-merchant'
            && $request['amount'] === 160000
            && $request['callbackUrl'] === 'https://example.com/callback';
    });
});

test('purchase failure throws PurchaseFailedException', function () {
    Http::fake([
        'gateway.zibal.ir/v1/request' => Http::response([
            'result' => 102,
            'message' => 'merchant یافت نشد.',
        ]),
    ]);

    $gateway = createZibalGateway();
    $invoice = createZibalInvoice();
    $gateway->setInvoice($invoice);

    $gateway->purchase();
})->throws(PurchaseFailedException::class);

test('purchase maps official error code to english message', function () {
    Http::fake([
        'gateway.zibal.ir/v1/request' => Http::response([
            'result' => 115,
            'message' => 'ip شما در پنل کاربری ثبت نشده است.',
        ]),
    ]);

    $gateway = createZibalGateway();
    $invoice = createZibalInvoice();
    $gateway->setInvoice($invoice);

    $gateway->purchase();
})->throws(PurchaseFailedException::class, 'Your IP address is not registered in Zibal panel.');

test('purchase malformed response still throws PurchaseFailedException', function () {
    Http::fake([
        'gateway.zibal.ir/v1/request' => Http::response(
            '<html>Bad Gateway</html>',
            502,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    $gateway = createZibalGateway();
    $invoice = createZibalInvoice();
    $gateway->setInvoice($invoice);

    $gateway->purchase();
})->throws(PurchaseFailedException::class);

test('purchase sends optional fields when details provided', function () {
    Http::fake([
        'gateway.zibal.ir/v1/request' => Http::response([
            'trackId' => 99887766,
            'result' => 100,
            'message' => 'success',
        ]),
    ]);

    $gateway = createZibalGateway();
    $invoice = createZibalInvoice();
    $invoice->detail('order_id', 'ORD-456')
        ->detail('mobile', '09121234567');
    $gateway->setInvoice($invoice);

    $gateway->purchase();

    Http::assertSent(function ($request) {
        return $request['orderId'] === 'ORD-456'
            && $request['mobile'] === '09121234567';
    });
});

// ── Pay Tests ───────────────────────────────────────────────

test('pay returns correct redirect URL', function () {
    $gateway = createZibalGateway();
    $invoice = createZibalInvoice();
    $invoice->transactionId('15966442233311');
    $gateway->setInvoice($invoice);

    $response = $gateway->pay();

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($response->getUrl())->toBe('https://gateway.zibal.ir/start/15966442233311');
});

test('pay throws InvalidPaymentException when transaction id is missing', function () {
    $gateway = createZibalGateway();
    $invoice = createZibalInvoice();
    $gateway->setInvoice($invoice);

    $gateway->pay();
})->throws(InvalidPaymentException::class, 'Transaction ID (trackId) is required before calling pay or verify.');

// ── Verify Tests ────────────────────────────────────────────

test('verify success returns receipt with reference id', function () {
    Http::fake([
        'gateway.zibal.ir/v1/verify' => Http::response([
            'paidAt' => '2025-01-15T10:30:00',
            'amount' => 160000,
            'result' => 100,
            'status' => 1,
            'refNumber' => 123456789,
            'description' => 'Test payment',
            'cardNumber' => '6274-12**-****-5544',
            'orderId' => 'ORD-456',
            'message' => 'success',
        ]),
    ]);

    $gateway = createZibalGateway();
    $invoice = createZibalInvoice();
    $invoice->detail('order_id', 'ORD-456');
    $invoice->transactionId('15966442233311');
    $gateway->setInvoice($invoice);

    $receipt = $gateway->verify();

    expect($receipt)->toBeInstanceOf(ReceiptInterface::class)
        ->and($receipt->getReferenceId())->toBe('123456789')
        ->and($receipt->getDriver())->toBe('zibal')
        ->and($receipt->getRawData())->toHaveKey('cardNumber')
        ->and($receipt->getRawData()['orderId'])->toBe('ORD-456');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'gateway.zibal.ir/v1/verify')
            && $request['merchant'] === 'test-zibal-merchant'
            && $request['trackId'] === '15966442233311';
    });
});

test('verify fails when response amount does not match invoice amount', function () {
    Http::fake([
        'gateway.zibal.ir/v1/verify' => Http::response([
            'amount' => 150000,
            'result' => 100,
            'status' => 1,
            'refNumber' => 123456789,
            'message' => 'success',
        ]),
    ]);

    $gateway = createZibalGateway();
    $invoice = createZibalInvoice();
    $invoice->transactionId('15966442233311');
    $gateway->setInvoice($invoice);

    $gateway->verify();
})->throws(InvalidPaymentException::class, 'Verified payment amount does not match the expected invoice amount.');

test('verify fails when response order id does not match expected invoice order id', function () {
    Http::fake([
        'gateway.zibal.ir/v1/verify' => Http::response([
            'amount' => 160000,
            'result' => 100,
            'status' => 1,
            'refNumber' => 123456789,
            'orderId' => 'ORD-999',
            'message' => 'success',
        ]),
    ]);

    $gateway = createZibalGateway();
    $invoice = createZibalInvoice();
    $invoice->detail('order_id', 'ORD-456');
    $invoice->transactionId('15966442233311');
    $gateway->setInvoice($invoice);

    $gateway->verify();
})->throws(InvalidPaymentException::class, 'Verified payment order ID does not match the expected invoice order ID.');

test('verify with code 201 (already verified) still returns receipt', function () {
    Http::fake([
        'gateway.zibal.ir/v1/verify' => Http::response([
            'result' => 201,
            'refNumber' => 123456789,
            'message' => 'قبلا تایید شده',
        ]),
    ]);

    $gateway = createZibalGateway();
    $invoice = createZibalInvoice();
    $invoice->transactionId('15966442233311');
    $gateway->setInvoice($invoice);

    $receipt = $gateway->verify();

    expect($receipt->getReferenceId())->toBe('123456789');
});

test('verify failure throws InvalidPaymentException', function () {
    Http::fake([
        'gateway.zibal.ir/v1/verify' => Http::response([
            'result' => 202,
            'message' => 'سفارش پرداخت نشده یا ناموفق بوده است.',
        ]),
    ]);

    $gateway = createZibalGateway();
    $invoice = createZibalInvoice();
    $invoice->transactionId('15966442233311');
    $gateway->setInvoice($invoice);

    $gateway->verify();
})->throws(InvalidPaymentException::class);

test('verify maps code 202 to english message', function () {
    Http::fake([
        'gateway.zibal.ir/v1/verify' => Http::response([
            'result' => 202,
            'message' => 'سفارش پرداخت نشده یا ناموفق بوده است.',
        ]),
    ]);

    $gateway = createZibalGateway();
    $invoice = createZibalInvoice();
    $invoice->transactionId('15966442233311');
    $gateway->setInvoice($invoice);

    $gateway->verify();
})->throws(InvalidPaymentException::class, 'Order is not paid or payment was unsuccessful.');

test('verify with invalid trackId throws InvalidPaymentException', function () {
    Http::fake([
        'gateway.zibal.ir/v1/verify' => Http::response([
            'result' => 203,
            'message' => 'trackId نامعتبر می‌باشد.',
        ]),
    ]);

    $gateway = createZibalGateway();
    $invoice = createZibalInvoice();
    $invoice->transactionId('invalid-track-id');
    $gateway->setInvoice($invoice);

    $gateway->verify();
})->throws(InvalidPaymentException::class, 'Invalid trackId.');

test('verify fails when successful response has no reference id', function () {
    Http::fake([
        'gateway.zibal.ir/v1/verify' => Http::response([
            'result' => 100,
            'message' => 'success',
        ]),
    ]);

    $gateway = createZibalGateway();
    $invoice = createZibalInvoice();
    $invoice->transactionId('15966442233311');
    $gateway->setInvoice($invoice);

    $gateway->verify();
})->throws(InvalidPaymentException::class, 'Zibal verification succeeded but no reference ID was returned.');

test('verify malformed response still throws InvalidPaymentException', function () {
    Http::fake([
        'gateway.zibal.ir/v1/verify' => Http::response(
            '<html>Bad Gateway</html>',
            502,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    $gateway = createZibalGateway();
    $invoice = createZibalInvoice();
    $invoice->transactionId('15966442233311');
    $gateway->setInvoice($invoice);

    $gateway->verify();
})->throws(InvalidPaymentException::class);

// ── Sandbox Tests ───────────────────────────────────────────

test('sandbox mode uses "zibal" as merchant for purchase', function () {
    Http::fake([
        'gateway.zibal.ir/v1/request' => Http::response([
            'trackId' => 99999,
            'result' => 100,
            'message' => 'success',
        ]),
    ]);

    $gateway = createZibalGateway(['sandbox' => true]);
    $invoice = createZibalInvoice();
    $gateway->setInvoice($invoice);

    $gateway->purchase();

    Http::assertSent(function ($request) {
        return $request['merchant'] === 'zibal';
    });
});

test('sandbox mode uses "zibal" as merchant for verify', function () {
    Http::fake([
        'gateway.zibal.ir/v1/verify' => Http::response([
            'result' => 100,
            'refNumber' => 555,
            'message' => 'success',
        ]),
    ]);

    $gateway = createZibalGateway(['sandbox' => true]);
    $invoice = createZibalInvoice();
    $invoice->transactionId('99999');
    $gateway->setInvoice($invoice);

    $gateway->verify();

    Http::assertSent(function ($request) {
        return $request['merchant'] === 'zibal';
    });
});

test('production mode uses configured merchant', function () {
    Http::fake([
        'gateway.zibal.ir/v1/request' => Http::response([
            'trackId' => 12345,
            'result' => 100,
            'message' => 'success',
        ]),
    ]);

    $gateway = createZibalGateway(['sandbox' => false, 'merchant' => 'my-real-merchant']);
    $invoice = createZibalInvoice();
    $gateway->setInvoice($invoice);

    $gateway->purchase();

    Http::assertSent(function ($request) {
        return $request['merchant'] === 'my-real-merchant';
    });
});

test('uses callback url from invoice when available', function () {
    Http::fake([
        'gateway.zibal.ir/v1/request' => Http::response([
            'trackId' => 55555,
            'result' => 100,
            'message' => 'success',
        ]),
    ]);

    $gateway = createZibalGateway();
    $invoice = createZibalInvoice();
    $invoice->callbackUrl('https://custom-callback.com/zibal-verify');
    $gateway->setInvoice($invoice);

    $gateway->purchase();

    Http::assertSent(function ($request) {
        return $request['callbackUrl'] === 'https://custom-callback.com/zibal-verify';
    });
});
