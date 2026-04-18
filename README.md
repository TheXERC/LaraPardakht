# LaraPardakht

A modern, extensible payment gateway integration package for Laravel 12, supporting Iranian payment providers.

## Features

- **Driver-based architecture** — easily add new gateways without modifying core code
- **Fluent API** — clean, chainable interface for purchases, payments and verifications
- **Sandbox/test support** — every driver supports sandbox mode out of the box
- **Events** — fires events after purchase and verification for easy integration
- **Runtime configuration** — switch drivers and override settings on the fly
- **Typed exceptions** — distinct exception classes for different failure scenarios

## Supported Gateways

| Gateway | Normal Mode | Sandbox Mode |
|---------|:-----------:|:------------:|
| [Zarinpal](https://www.zarinpal.com/) | ✅ | ✅ |
| [Zibal](https://zibal.ir/) | ✅ | ✅ |

More gateways coming soon! You can also [create custom drivers](#creating-custom-drivers).

## Requirements

- PHP 8.2+
- Laravel 11 or 12

## Installation

```bash
composer require larapardakht/larapardakht
```

### Publish Configuration

```bash
php artisan vendor:publish --tag=larapardakht-config
```

This will create `config/larapardakht.php` in your application.

## Configuration

Set your gateway credentials in `.env`:

```env
PAYMENT_GATEWAY=zarinpal

# Zarinpal
ZARINPAL_MERCHANT_ID=your-merchant-id-here
ZARINPAL_SANDBOX=false

# Zibal
ZIBAL_MERCHANT=your-zibal-merchant
ZIBAL_SANDBOX=false

# Shared
PAYMENT_CALLBACK_URL=https://yoursite.com/payment/callback
```

## Usage

### Purchase & Redirect

```php
use LaraPardakht\Facades\Payment;
use LaraPardakht\DTOs\Invoice;

$invoice = new Invoice();
$invoice->amount(50000)
    ->description('Order #123')
    ->detail('mobile', '09121234567')
    ->detail('email', 'customer@example.com');

return Payment::purchase($invoice, function ($driver, $transactionId) {
    // Store $transactionId in your database
    Order::find($orderId)->update(['transaction_id' => $transactionId]);
})->pay()->render();
```

### Verify Payment

```php
use LaraPardakht\Facades\Payment;
use LaraPardakht\Exceptions\InvalidPaymentException;

try {
    $receipt = Payment::amount(50000)
        ->transactionId($transactionId)
        ->verify();

    // Payment was successful
    echo $receipt->getReferenceId();
    echo $receipt->getDriver();

} catch (InvalidPaymentException $e) {
    // Payment verification failed
    echo $e->getMessage();
}
```

### Switch Driver at Runtime

```php
Payment::via('zibal')->purchase($invoice, function ($driver, $transactionId) {
    // ...
});
```

### Override Config at Runtime

```php
Payment::config('merchant_id', 'another-merchant-id')->purchase($invoice);

// Or multiple values:
Payment::config([
    'merchant_id' => 'another-merchant-id',
    'sandbox' => true,
])->purchase($invoice);
```

### Override Callback URL

```php
Payment::callbackUrl('https://yoursite.com/custom-callback')
    ->purchase($invoice);
```

### Get JSON Redirect Data

```php
$redirect = Payment::purchase($invoice)->pay();
return $redirect->toJson();
```

## Security and Reliability Notes

- `PaymentManager` is now container-scoped (request/job lifecycle) to avoid cross-request state leakage in long-lived workers.
- Facade root caching is disabled for `Payment`, so each call resolves from the current container scope.
- `pay()` now validates that a transaction identifier exists and throws `InvalidPaymentException` when missing.
- `verify()` now validates that a reference identifier exists in successful gateway responses and throws `InvalidPaymentException` when missing.
- `Zibal` verify now performs consistency checks against local invoice data when available:
    - If invoice amount is set (> 0) and gateway returns `amount`, values must match.
    - If invoice `order_id` detail is set and gateway returns `orderId`, values must match.
- Malformed/non-JSON gateway responses are handled safely and converted to typed exceptions (`PurchaseFailedException` / `InvalidPaymentException`).

### Compatibility Impact

No public method signatures changed and no config changes are required.

If your integration previously called `pay()` before a successful `purchase()`, or relied on low-level runtime errors for malformed gateway responses, update your error handling to catch typed gateway exceptions.

For `Zibal` only: verify may now throw `InvalidPaymentException` when gateway-reported `amount` / `orderId` conflicts with your local invoice data. This is a security hardening change. No API update is required on your side, but ensure your verify flow handles this exception and keeps local invoice values authoritative.

## Gateway Codes (English Translations)

These translations are used by the package when possible so exception messages are predictable.

### Zarinpal - Common Codes

| Code | Meaning |
|---:|---|
| -9 | Validation error |
| -10 | Terminal is not valid (check merchant_id or IP) |
| -11 | Terminal is not active |
| -12 | Too many attempts |
| -13 | Terminal limit reached |
| -14 | Callback domain does not match registered terminal domain |
| -15 | Terminal user is suspended |
| -16 | Terminal user level is not valid |
| -17 | Terminal user level is not valid |
| -18 | Referrer address does not match registered domain |
| -19 | Terminal transactions are banned |
| 100 | Success |
| 101 | Already verified |

### Zarinpal - Purchase Codes

| Code | Meaning |
|---:|---|
| -30 | Terminal does not allow floating wages |
| -31 | Terminal does not allow wages (default bank account missing) |
| -32 | Invalid wages (floating total exceeds max amount) |
| -33 | Invalid floating wages |
| -34 | Invalid wages (fixed total exceeds max amount) |
| -35 | Floating wages reached max parts limit |
| -36 | Minimum floating wage amount is 10,000 Rials |
| -37 | One or more wage IBAN values are inactive |
| -38 | Wage IBAN setup is invalid |
| -39 | Generic wages error |
| -40 | Invalid `expire_in` |
| -41 | Maximum amount is 100,000,000 Tomans |

### Zarinpal - Verify Codes

| Code | Meaning |
|---:|---|
| -50 | Session invalid (amount mismatch) |
| -51 | Session invalid (payment not successful / inactive session) |
| -52 | Unexpected system error |
| -53 | Session does not belong to this merchant_id |
| -54 | Invalid authority |
| -55 | Manual payment request not found |

### Zibal - Request Result Codes

| Code | Meaning |
|---:|---|
| 100 | Success |
| 102 | Merchant not found |
| 103 | Merchant inactive / gateway contract not signed |
| 104 | Invalid merchant |
| 105 | Amount must be greater than 1,000 Rials |
| 106 | Invalid callbackUrl (must start with http/https) |
| 107 | Invalid percentMode (only 0 or 1) |
| 108 | One or more beneficiaries in multiplexingInfos are invalid |
| 109 | One or more beneficiaries in multiplexingInfos are inactive |
| 110 | `id=self` missing in multiplexingInfos |
| 111 | Amount is not equal to total shares in multiplexingInfos |
| 112 | Insufficient fee wallet balance |
| 113 | Amount exceeds transaction limit |
| 114 | Invalid national code |
| 115 | IP is not registered in panel |

### Zibal - Verify Result Codes

| Code | Meaning |
|---:|---|
| 100 | Success |
| 102 | Merchant not found |
| 103 | Merchant inactive |
| 104 | Invalid merchant |
| 201 | Already verified |
| 202 | Order not paid or payment unsuccessful |
| 203 | Invalid trackId |

### Zibal - Payment Status Codes

| Status | Meaning |
|---:|---|
| -1 | Waiting for payment |
| -2 | Internal error |
| 1 | Paid and verified |
| 2 | Paid and unverified |
| 3 | Cancelled by user |
| 4 | Invalid card number |
| 5 | Insufficient funds |
| 6 | Invalid password/PIN |
| 7 | Too many requests |
| 8 | Daily internet payment count limit exceeded |
| 9 | Daily internet payment amount limit exceeded |
| 10 | Invalid card issuer |
| 11 | Switch error |
| 12 | Card not accessible |
| 15 | Refunded |
| 16 | Refund in progress |
| 18 | Reversed |
| 21 | Invalid merchant |

## Creating Custom Drivers

### 1. Create a Gateway Class

Create a new directory under `src/Drivers/YourGateway/` and implement `GatewayInterface`:

```php
<?php

declare(strict_types=1);

namespace LaraPardakht\Drivers\MyGateway;

use LaraPardakht\Contracts\GatewayInterface;
use LaraPardakht\Contracts\InvoiceInterface;
use LaraPardakht\Contracts\ReceiptInterface;
use LaraPardakht\DTOs\Receipt;
use LaraPardakht\DTOs\RedirectResponse;

class MyGatewayGateway implements GatewayInterface
{
    protected InvoiceInterface $invoice;

    public function __construct(
        protected readonly array $settings,
    ) {}

    public function setInvoice(InvoiceInterface $invoice): static
    {
        $this->invoice = $invoice;
        return $this;
    }

    public function purchase(): string
    {
        // Send purchase request to gateway API
        // Return the transaction ID
    }

    public function pay(): RedirectResponse
    {
        // Return redirect URL to gateway payment page
        return new RedirectResponse(url: 'https://gateway.com/pay/' . $this->invoice->getTransactionId());
    }

    public function verify(): ReceiptInterface
    {
        // Verify the payment
        // Return a Receipt
        return new Receipt(
            referenceId: 'ref-123',
            driver: 'mygateway',
            date: new \DateTimeImmutable(),
            rawData: [],
        );
    }
}
```

### 2. Register in Config

Add your driver to `config/larapardakht.php`:

```php
'drivers' => [
    // ... existing drivers
    'mygateway' => [
        'api_key' => env('MYGATEWAY_API_KEY', ''),
        'sandbox' => env('MYGATEWAY_SANDBOX', false),
        'callback_url' => env('PAYMENT_CALLBACK_URL', ''),
    ],
],

'map' => [
    // ... existing mappings
    'mygateway' => \LaraPardakht\Drivers\MyGateway\MyGatewayGateway::class,
],
```

### 3. Write Tests

Add tests under `tests/Drivers/MyGateway/` using `Http::fake()` to mock API calls.

## Events

| Event | When Fired |
|-------|-----------|
| `PaymentPurchased` | After a successful purchase (transaction ID obtained) |
| `PaymentVerified` | After a successful payment verification |

```php
use LaraPardakht\Events\PaymentPurchased;
use LaraPardakht\Events\PaymentVerified;

// In your EventServiceProvider or listener
Event::listen(PaymentPurchased::class, function ($event) {
    logger("Payment purchased: {$event->transactionId} via {$event->driver}");
});

Event::listen(PaymentVerified::class, function ($event) {
    logger("Payment verified: {$event->receipt->getReferenceId()} via {$event->driver}");
});
```

## Testing

Run the test suite:

```bash
./vendor/bin/pest
```

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.
