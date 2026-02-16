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
