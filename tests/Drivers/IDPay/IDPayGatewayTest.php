<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use LaraPardakht\Contracts\ReceiptInterface;
use LaraPardakht\Drivers\IDPay\IDPayGateway;
use LaraPardakht\DTOs\Invoice;
use LaraPardakht\DTOs\RedirectResponse;
use LaraPardakht\Exceptions\InvalidPaymentException;
use LaraPardakht\Exceptions\PurchaseFailedException;

uses(\LaraPardakht\Tests\TestCase::class);

function createIDPayGateway(array $settingsOverride = []): IDPayGateway
{
    $settings = array_merge([
        'api_key' => 'test-idpay-api-key',
        'sandbox' => false,
        'description' => 'Test payment via IDPay',
        'callback_url' => 'https://example.com/callback',
    ], $settingsOverride);

    return new IDPayGateway($settings);
}

function createIDPayInvoice(
    int $amount = 10000,
    string $description = 'Test IDPay order',
    string $orderId = 'ORD-1001',
): Invoice {
    $invoice = new Invoice();
    $invoice->amount($amount)
        ->description($description)
        ->detail('order_id', $orderId);

    return $invoice;
}

// ── Purchase Tests ──────────────────────────────────────────

test('purchase success returns id as transaction id', function () {
    Http::fake([
        'api.idpay.ir/v1.1/payment' => Http::response([
            'id' => 'd2e353189823079e1e4181772cff5292',
            'link' => 'https://idpay.ir/p/ws-sandbox/d2e353189823079e1e4181772cff5292',
        ], 201),
    ]);

    $gateway = createIDPayGateway();
    $invoice = createIDPayInvoice();
    $gateway->setInvoice($invoice);

    $transactionId = $gateway->purchase();

    expect($transactionId)->toBe('d2e353189823079e1e4181772cff5292')
        ->and($invoice->getDetails()['idpay_link'])
        ->toBe('https://idpay.ir/p/ws-sandbox/d2e353189823079e1e4181772cff5292');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.idpay.ir/v1.1/payment')
            && $request['order_id'] === 'ORD-1001'
            && $request['amount'] === 10000
            && $request['desc'] === 'Test IDPay order'
            && $request['callback'] === 'https://example.com/callback'
            && $request->hasHeader('X-API-KEY', 'test-idpay-api-key')
            && ! $request->hasHeader('X-SANDBOX');
    });
});

test('purchase failure throws PurchaseFailedException', function () {
    Http::fake([
        'api.idpay.ir/v1.1/payment' => Http::response([
            'error_code' => 12,
            'error_message' => 'API Key پیدا نشد.',
        ], 403),
    ]);

    $gateway = createIDPayGateway();
    $invoice = createIDPayInvoice();
    $gateway->setInvoice($invoice);

    $gateway->purchase();
})->throws(PurchaseFailedException::class, 'API key was not found.');

test('purchase connection exception throws PurchaseFailedException', function () {
    Http::fake(function () {
        throw new ConnectionException('Connection timed out.');
    });

    $gateway = createIDPayGateway();
    $invoice = createIDPayInvoice();
    $gateway->setInvoice($invoice);

    $gateway->purchase();
})->throws(PurchaseFailedException::class, 'Unable to connect to IDPay purchase endpoint.');

test('purchase requires order id', function () {
    $gateway = createIDPayGateway();
    $invoice = new Invoice();
    $invoice->amount(10000)->description('No order id');
    $gateway->setInvoice($invoice);

    $gateway->purchase();
})->throws(PurchaseFailedException::class, 'Order ID (order_id) is required for IDPay purchase. Use invoice detail order_id.');

test('purchase requires callback url', function () {
    $gateway = createIDPayGateway(['callback_url' => '']);
    $invoice = createIDPayInvoice();
    $gateway->setInvoice($invoice);

    $gateway->purchase();
})->throws(PurchaseFailedException::class, 'Callback URL is required for IDPay purchase. Set callback_url config or invoice callback URL.');

test('purchase malformed response still throws PurchaseFailedException', function () {
    Http::fake([
        'api.idpay.ir/v1.1/payment' => Http::response(
            '<html>Bad Gateway</html>',
            502,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    $gateway = createIDPayGateway();
    $invoice = createIDPayInvoice();
    $gateway->setInvoice($invoice);

    $gateway->purchase();
})->throws(PurchaseFailedException::class);

test('purchase sends optional payer fields from invoice details', function () {
    Http::fake([
        'api.idpay.ir/v1.1/payment' => Http::response([
            'id' => 'abc123abc123abc123abc123abc123ab',
            'link' => 'https://idpay.ir/p/ws-sandbox/abc123abc123abc123abc123abc123ab',
        ], 201),
    ]);

    $gateway = createIDPayGateway();
    $invoice = createIDPayInvoice();
    $invoice->detail('name', 'John Doe')
        ->detail('mobile', '09121234567')
        ->detail('email', 'john@example.com');
    $gateway->setInvoice($invoice);

    $gateway->purchase();

    Http::assertSent(function ($request) {
        return $request['name'] === 'John Doe'
            && $request['phone'] === '09121234567'
            && $request['mail'] === 'john@example.com';
    });
});

// ── Pay Tests ───────────────────────────────────────────────

test('pay returns redirect URL from purchase link', function () {
    Http::fake([
        'api.idpay.ir/v1.1/payment' => Http::response([
            'id' => 'pay111111111111111111111111111111',
            'link' => 'https://idpay.ir/p/ws-sandbox/pay111111111111111111111111111111',
        ], 201),
    ]);

    $gateway = createIDPayGateway();
    $invoice = createIDPayInvoice();
    $gateway->setInvoice($invoice);

    $gateway->purchase();
    $response = $gateway->pay();

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($response->getUrl())
        ->toBe('https://idpay.ir/p/ws-sandbox/pay111111111111111111111111111111');
});

test('pay returns redirect URL when idpay_link detail is provided', function () {
    $gateway = createIDPayGateway();
    $invoice = createIDPayInvoice();
    $invoice->detail('idpay_link', 'https://idpay.ir/p/ws-sandbox/manual-link-id');
    $gateway->setInvoice($invoice);

    $response = $gateway->pay();

    expect($response->getUrl())->toBe('https://idpay.ir/p/ws-sandbox/manual-link-id');
});

test('pay throws InvalidPaymentException when payment link is missing', function () {
    $gateway = createIDPayGateway();
    $invoice = createIDPayInvoice();
    $gateway->setInvoice($invoice);

    $gateway->pay();
})->throws(InvalidPaymentException::class, 'IDPay payment link is required before calling pay. Call purchase first or provide invoice detail idpay_link.');

// ── Verify Tests ────────────────────────────────────────────

test('verify success returns receipt with reference id', function () {
    Http::fake([
        'api.idpay.ir/v1.1/payment/verify' => Http::response([
            'status' => 100,
            'track_id' => 726298033,
            'id' => 'd2e353189823079e1e4181772cff5292',
            'order_id' => 'ORD-1001',
            'amount' => 10000,
            'payment' => [
                'track_id' => '726298033',
                'amount' => 10000,
                'card_no' => '603799******1234',
            ],
            'verify' => [
                'date' => 1700001000,
            ],
        ], 200),
    ]);

    $gateway = createIDPayGateway();
    $invoice = createIDPayInvoice();
    $invoice->transactionId('d2e353189823079e1e4181772cff5292');
    $gateway->setInvoice($invoice);

    $receipt = $gateway->verify();

    expect($receipt)->toBeInstanceOf(ReceiptInterface::class)
        ->and($receipt->getReferenceId())->toBe('726298033')
        ->and($receipt->getDriver())->toBe('idpay')
        ->and($receipt->getRawData()['status'])->toBe(100)
        ->and($receipt->getRawData()['payment']['card_no'])->toBe('603799******1234');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.idpay.ir/v1.1/payment/verify')
            && $request['id'] === 'd2e353189823079e1e4181772cff5292'
            && $request['order_id'] === 'ORD-1001'
            && $request->hasHeader('X-API-KEY', 'test-idpay-api-key');
    });
});

test('verify with status 101 (already verified) still returns receipt', function () {
    Http::fake([
        'api.idpay.ir/v1.1/payment/verify' => Http::response([
            'status' => 101,
            'track_id' => 726298033,
            'id' => 'd2e353189823079e1e4181772cff5292',
            'order_id' => 'ORD-1001',
            'amount' => 10000,
        ], 200),
    ]);

    $gateway = createIDPayGateway();
    $invoice = createIDPayInvoice();
    $invoice->transactionId('d2e353189823079e1e4181772cff5292');
    $gateway->setInvoice($invoice);

    $receipt = $gateway->verify();

    expect($receipt->getReferenceId())->toBe('726298033')
        ->and($receipt->getRawData()['already_verified'])->toBeTrue();
});

test('verify failure throws InvalidPaymentException', function () {
    Http::fake([
        'api.idpay.ir/v1.1/payment/verify' => Http::response([
            'error_code' => 53,
            'error_message' => 'تایید پرداخت ممکن نیست.',
        ], 405),
    ]);

    $gateway = createIDPayGateway();
    $invoice = createIDPayInvoice();
    $invoice->transactionId('d2e353189823079e1e4181772cff5292');
    $gateway->setInvoice($invoice);

    $gateway->verify();
})->throws(InvalidPaymentException::class, 'Payment verification is not possible.');

test('verify maps non-success status to english message', function () {
    Http::fake([
        'api.idpay.ir/v1.1/payment/verify' => Http::response([
            'status' => 2,
            'track_id' => 726298033,
            'id' => 'd2e353189823079e1e4181772cff5292',
            'order_id' => 'ORD-1001',
            'amount' => 10000,
        ], 200),
    ]);

    $gateway = createIDPayGateway();
    $invoice = createIDPayInvoice();
    $invoice->transactionId('d2e353189823079e1e4181772cff5292');
    $gateway->setInvoice($invoice);

    $gateway->verify();
})->throws(InvalidPaymentException::class, 'Payment failed.');

test('verify fails when amount does not match invoice amount', function () {
    Http::fake([
        'api.idpay.ir/v1.1/payment/verify' => Http::response([
            'status' => 100,
            'track_id' => 726298033,
            'id' => 'd2e353189823079e1e4181772cff5292',
            'order_id' => 'ORD-1001',
            'amount' => 9999,
        ], 200),
    ]);

    $gateway = createIDPayGateway();
    $invoice = createIDPayInvoice();
    $invoice->transactionId('d2e353189823079e1e4181772cff5292');
    $gateway->setInvoice($invoice);

    $gateway->verify();
})->throws(InvalidPaymentException::class, 'Verified payment amount does not match the expected invoice amount.');

test('verify fails when order id does not match expected invoice order id', function () {
    Http::fake([
        'api.idpay.ir/v1.1/payment/verify' => Http::response([
            'status' => 100,
            'track_id' => 726298033,
            'id' => 'd2e353189823079e1e4181772cff5292',
            'order_id' => 'ORD-NOT-MATCH',
            'amount' => 10000,
        ], 200),
    ]);

    $gateway = createIDPayGateway();
    $invoice = createIDPayInvoice();
    $invoice->transactionId('d2e353189823079e1e4181772cff5292');
    $gateway->setInvoice($invoice);

    $gateway->verify();
})->throws(InvalidPaymentException::class, 'Verified payment order ID does not match the expected invoice order ID.');

test('verify fails when transaction id does not match expected transaction id', function () {
    Http::fake([
        'api.idpay.ir/v1.1/payment/verify' => Http::response([
            'status' => 100,
            'track_id' => 726298033,
            'id' => 'another-id-not-matching',
            'order_id' => 'ORD-1001',
            'amount' => 10000,
        ], 200),
    ]);

    $gateway = createIDPayGateway();
    $invoice = createIDPayInvoice();
    $invoice->transactionId('d2e353189823079e1e4181772cff5292');
    $gateway->setInvoice($invoice);

    $gateway->verify();
})->throws(InvalidPaymentException::class, 'Verified payment transaction ID does not match the expected transaction ID.');

test('verify requires amount greater than zero', function () {
    $gateway = createIDPayGateway();
    $invoice = new Invoice();
    $invoice->transactionId('d2e353189823079e1e4181772cff5292')
        ->detail('order_id', 'ORD-1001');
    $gateway->setInvoice($invoice);

    $gateway->verify();
})->throws(InvalidPaymentException::class, 'Invoice amount is required and must be greater than zero before calling verify.');

test('verify requires transaction id', function () {
    $gateway = createIDPayGateway();
    $invoice = createIDPayInvoice();
    $gateway->setInvoice($invoice);

    $gateway->verify();
})->throws(InvalidPaymentException::class, 'Transaction ID (id) is required before calling verify.');

test('verify requires order id', function () {
    $gateway = createIDPayGateway();
    $invoice = new Invoice();
    $invoice->amount(10000)->transactionId('d2e353189823079e1e4181772cff5292');
    $gateway->setInvoice($invoice);

    $gateway->verify();
})->throws(InvalidPaymentException::class, 'Order ID (order_id) is required before calling verify.');

test('verify fails when successful response has no reference id', function () {
    Http::fake([
        'api.idpay.ir/v1.1/payment/verify' => Http::response([
            'status' => 100,
            'id' => 'd2e353189823079e1e4181772cff5292',
            'order_id' => 'ORD-1001',
            'amount' => 10000,
        ], 200),
    ]);

    $gateway = createIDPayGateway();
    $invoice = createIDPayInvoice();
    $invoice->transactionId('d2e353189823079e1e4181772cff5292');
    $gateway->setInvoice($invoice);

    $gateway->verify();
})->throws(InvalidPaymentException::class, 'IDPay verification succeeded but no reference ID was returned.');

test('verify malformed response still throws InvalidPaymentException', function () {
    Http::fake([
        'api.idpay.ir/v1.1/payment/verify' => Http::response(
            '<html>Bad Gateway</html>',
            502,
            ['Content-Type' => 'text/html'],
        ),
    ]);

    $gateway = createIDPayGateway();
    $invoice = createIDPayInvoice();
    $invoice->transactionId('d2e353189823079e1e4181772cff5292');
    $gateway->setInvoice($invoice);

    $gateway->verify();
})->throws(InvalidPaymentException::class);

test('verify connection exception throws InvalidPaymentException', function () {
    Http::fake(function () {
        throw new ConnectionException('Connection timed out.');
    });

    $gateway = createIDPayGateway();
    $invoice = createIDPayInvoice();
    $invoice->transactionId('d2e353189823079e1e4181772cff5292');
    $gateway->setInvoice($invoice);

    $gateway->verify();
})->throws(InvalidPaymentException::class, 'Unable to connect to IDPay verify endpoint.');

// ── Sandbox Tests ───────────────────────────────────────────

test('sandbox mode sends sandbox header for purchase', function () {
    Http::fake([
        'api.idpay.ir/v1.1/payment' => Http::response([
            'id' => 'sandbox-id-123456789012345678901234',
            'link' => 'https://idpay.ir/p/ws-sandbox/sandbox-id-123456789012345678901234',
        ], 201),
    ]);

    $gateway = createIDPayGateway(['sandbox' => true]);
    $invoice = createIDPayInvoice();
    $gateway->setInvoice($invoice);

    $gateway->purchase();

    Http::assertSent(function ($request) {
        return $request->hasHeader('X-SANDBOX', '1');
    });
});

test('sandbox mode sends sandbox header for verify', function () {
    Http::fake([
        'api.idpay.ir/v1.1/payment/verify' => Http::response([
            'status' => 100,
            'track_id' => 726298033,
            'id' => 'd2e353189823079e1e4181772cff5292',
            'order_id' => 'ORD-1001',
            'amount' => 10000,
        ], 200),
    ]);

    $gateway = createIDPayGateway(['sandbox' => true]);
    $invoice = createIDPayInvoice();
    $invoice->transactionId('d2e353189823079e1e4181772cff5292');
    $gateway->setInvoice($invoice);

    $gateway->verify();

    Http::assertSent(function ($request) {
        return $request->hasHeader('X-SANDBOX', '1');
    });
});
