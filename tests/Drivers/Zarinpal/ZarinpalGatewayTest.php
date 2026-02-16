<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use LaraPardakht\Contracts\ReceiptInterface;
use LaraPardakht\Drivers\Zarinpal\ZarinpalGateway;
use LaraPardakht\DTOs\Invoice;
use LaraPardakht\DTOs\RedirectResponse;
use LaraPardakht\Exceptions\InvalidPaymentException;
use LaraPardakht\Exceptions\PurchaseFailedException;

uses(\LaraPardakht\Tests\TestCase::class);

function createZarinpalGateway(array $settingsOverride = []): ZarinpalGateway
{
    $settings = array_merge([
        'merchant_id' => 'test-merchant-id-xxxx-xxxx-xxxxxxxxxxxx',
        'sandbox' => false,
        'description' => 'Test payment',
        'callback_url' => 'https://example.com/callback',
    ], $settingsOverride);

    return new ZarinpalGateway($settings);
}

function createZarinpalInvoice(int $amount = 50000, string $description = 'Test order'): Invoice
{
    $invoice = new Invoice();
    $invoice->amount($amount)->description($description);

    return $invoice;
}

// ── Purchase Tests ──────────────────────────────────────────

test('purchase success returns authority as transaction id', function () {
    Http::fake([
        'payment.zarinpal.com/pg/v4/payment/request.json' => Http::response([
            'data' => [
                'code' => 100,
                'message' => 'Success',
                'authority' => 'A00000000000000000000000000001234567',
                'fee_type' => 'Merchant',
                'fee' => 100,
            ],
            'errors' => [],
        ]),
    ]);

    $gateway = createZarinpalGateway();
    $invoice = createZarinpalInvoice();
    $gateway->setInvoice($invoice);

    $transactionId = $gateway->purchase();

    expect($transactionId)->toBe('A00000000000000000000000000001234567');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'payment.zarinpal.com/pg/v4/payment/request.json')
            && $request['merchant_id'] === 'test-merchant-id-xxxx-xxxx-xxxxxxxxxxxx'
            && $request['amount'] === 50000
            && $request['description'] === 'Test order'
            && $request['callback_url'] === 'https://example.com/callback';
    });
});

test('purchase failure throws PurchaseFailedException', function () {
    Http::fake([
        'payment.zarinpal.com/pg/v4/payment/request.json' => Http::response([
            'data' => [],
            'errors' => [
                'code' => -9,
                'message' => 'The input params invalid, validation error.',
                'validations' => [],
            ],
        ]),
    ]);

    $gateway = createZarinpalGateway();
    $invoice = createZarinpalInvoice();
    $gateway->setInvoice($invoice);

    $gateway->purchase();
})->throws(PurchaseFailedException::class);

test('purchase sends metadata when details include mobile and email', function () {
    Http::fake([
        'payment.zarinpal.com/pg/v4/payment/request.json' => Http::response([
            'data' => [
                'code' => 100,
                'authority' => 'A00000000000000000000000000009999999',
            ],
            'errors' => [],
        ]),
    ]);

    $gateway = createZarinpalGateway();
    $invoice = createZarinpalInvoice();
    $invoice->detail('mobile', '09121234567')
        ->detail('email', 'test@example.com')
        ->detail('order_id', 'ORD-123');
    $gateway->setInvoice($invoice);

    $gateway->purchase();

    Http::assertSent(function ($request) {
        return $request['metadata']['mobile'] === '09121234567'
            && $request['metadata']['email'] === 'test@example.com'
            && $request['metadata']['order_id'] === 'ORD-123';
    });
});

// ── Pay Tests ───────────────────────────────────────────────

test('pay returns correct redirect URL', function () {
    $gateway = createZarinpalGateway();
    $invoice = createZarinpalInvoice();
    $invoice->transactionId('A00000000000000000000000000001234567');
    $gateway->setInvoice($invoice);

    $response = $gateway->pay();

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($response->getUrl())->toBe('https://payment.zarinpal.com/pg/StartPay/A00000000000000000000000000001234567');
});

// ── Verify Tests ────────────────────────────────────────────

test('verify success returns receipt with reference id', function () {
    Http::fake([
        'payment.zarinpal.com/pg/v4/payment/verify.json' => Http::response([
            'data' => [
                'code' => 100,
                'message' => 'Verified',
                'card_hash' => '1EBE3EBEBE35C7EC0F8D6EE4F2F859107A87822CA179BC9528767EA7B5489B69',
                'card_pan' => '502229******5995',
                'ref_id' => 201,
                'fee_type' => 'Merchant',
                'fee' => 0,
            ],
            'errors' => [],
        ]),
    ]);

    $gateway = createZarinpalGateway();
    $invoice = createZarinpalInvoice();
    $invoice->transactionId('A00000000000000000000000000001234567');
    $gateway->setInvoice($invoice);

    $receipt = $gateway->verify();

    expect($receipt)->toBeInstanceOf(ReceiptInterface::class)
        ->and($receipt->getReferenceId())->toBe('201')
        ->and($receipt->getDriver())->toBe('zarinpal')
        ->and($receipt->getRawData())->toHaveKey('card_pan')
        ->and($receipt->getRawData()['card_pan'])->toBe('502229******5995');
});

test('verify with code 101 (already verified) still returns receipt', function () {
    Http::fake([
        'payment.zarinpal.com/pg/v4/payment/verify.json' => Http::response([
            'data' => [
                'code' => 101,
                'message' => 'Already Verified',
                'ref_id' => 201,
            ],
            'errors' => [],
        ]),
    ]);

    $gateway = createZarinpalGateway();
    $invoice = createZarinpalInvoice();
    $invoice->transactionId('A00000000000000000000000000001234567');
    $gateway->setInvoice($invoice);

    $receipt = $gateway->verify();

    expect($receipt->getReferenceId())->toBe('201');
});

test('verify failure throws InvalidPaymentException', function () {
    Http::fake([
        'payment.zarinpal.com/pg/v4/payment/verify.json' => Http::response([
            'data' => [],
            'errors' => [
                'code' => -51,
                'message' => 'Payment not successful.',
            ],
        ]),
    ]);

    $gateway = createZarinpalGateway();
    $invoice = createZarinpalInvoice();
    $invoice->transactionId('A00000000000000000000000000001234567');
    $gateway->setInvoice($invoice);

    $gateway->verify();
})->throws(InvalidPaymentException::class);

// ── Sandbox Tests ───────────────────────────────────────────

test('sandbox mode uses sandbox URL for purchase', function () {
    Http::fake([
        'sandbox.zarinpal.com/pg/v4/payment/request.json' => Http::response([
            'data' => [
                'code' => 100,
                'authority' => 'S00000000000000000000000000001234567',
            ],
            'errors' => [],
        ]),
    ]);

    $gateway = createZarinpalGateway(['sandbox' => true]);
    $invoice = createZarinpalInvoice();
    $gateway->setInvoice($invoice);

    $transactionId = $gateway->purchase();

    expect($transactionId)->toBe('S00000000000000000000000000001234567');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'sandbox.zarinpal.com');
    });
});

test('sandbox mode uses sandbox URL for pay redirect', function () {
    $gateway = createZarinpalGateway(['sandbox' => true]);
    $invoice = createZarinpalInvoice();
    $invoice->transactionId('S00000000000000000000000000001234567');
    $gateway->setInvoice($invoice);

    $response = $gateway->pay();

    expect($response->getUrl())->toContain('sandbox.zarinpal.com');
});

test('sandbox mode uses sandbox URL for verify', function () {
    Http::fake([
        'sandbox.zarinpal.com/pg/v4/payment/verify.json' => Http::response([
            'data' => [
                'code' => 100,
                'ref_id' => 999,
            ],
            'errors' => [],
        ]),
    ]);

    $gateway = createZarinpalGateway(['sandbox' => true]);
    $invoice = createZarinpalInvoice();
    $invoice->transactionId('S00000000000000000000000000001234567');
    $gateway->setInvoice($invoice);

    $receipt = $gateway->verify();

    expect($receipt->getReferenceId())->toBe('999');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'sandbox.zarinpal.com');
    });
});

test('uses callback url from invoice when available', function () {
    Http::fake([
        'payment.zarinpal.com/pg/v4/payment/request.json' => Http::response([
            'data' => [
                'code' => 100,
                'authority' => 'A00000000000000000000000000001234567',
            ],
            'errors' => [],
        ]),
    ]);

    $gateway = createZarinpalGateway();
    $invoice = createZarinpalInvoice();
    $invoice->callbackUrl('https://custom-callback.com/verify');
    $gateway->setInvoice($invoice);

    $gateway->purchase();

    Http::assertSent(function ($request) {
        return $request['callback_url'] === 'https://custom-callback.com/verify';
    });
});
