# Security Policy

This document outlines security practices and important changes for the LaraPardakht payment gateway package.

## Security Features

### SSL/TLS Verification
All HTTP requests to payment gateways have **explicit SSL/TLS certificate verification enabled** (`verify(true)`). This ensures secure communication and protects against man-in-the-middle attacks.

- **Status**: ✅ Enabled by default
- **Verification**: All gateway API calls verify SSL certificates
- **Production Impact**: Critical for payment processing - no configuration needed

### Request Timeouts
All HTTP requests to payment gateways have a **30-second timeout**. This prevents indefinite hanging if a gateway becomes unresponsive.

- **Timeout**: 30 seconds per request
- **Applies to**: All gateway purchase and verify operations
- **What to do**: If you experience timeouts, ensure your gateway credentials are valid and your server has stable internet connectivity

### URL Validation
Payment callback and redirect URLs undergo strict validation:

- **Requirement**: All URLs must use HTTPS in production (HTTP allowed only for localhost)
- **Validation**: URLs are parsed and verified before use
- **Protection**: Prevents URL injection and manipulation attacks
- **Impact**: HTTP URLs in production will throw `InvalidPaymentException`

### Sensitive Data Protection in Exceptions
Exceptions contain sanitized gateway responses:

- **What's Protected**: Merchant IDs, tokens, card details, authentication data are never logged
- **What's Included**: Only safe fields like error codes, status messages, timestamps
- **Benefit**: Prevents credential leakage via logs or error reporting services
- **Implementation**: All fields are filtered through a whitelist (safe fields only)

**Safe fields that may appear in exceptions:**
- `code`, `result`, `status` (gateway response codes)
- `message`, `error_message` (human-readable errors)
- `errors` (error arrays)
- `timestamp`, `date` (when the error occurred)

**Sensitive fields that are ALWAYS filtered out:**
- `merchant_id`, `merchant` (your gateway credentials)
- `token`, `authorization`, `auth_*` (authentication data)
- `card_*`, `pan`, `cvv` (card details - never exposed)
- `secret_*`, `key_*` (API secrets)
- Any other sensitive payment data

### Input Validation
All invoice data is validated before sending to payment gateways:

- **Amount Validation**: Must be positive integer, maximum 100 million Rials (adjustable per your needs)
- **Description Validation**: Non-empty, max 255 characters, no null bytes
- **Email Validation**: Proper format per RFC 5321 standard
- **Phone Validation**: 9-15 digits, optionally starting with +
- **Invalid Data**: Throws `InvalidPaymentException` immediately with clear error message

**Example - Validation in Action:**

```php
use LaraPardakht\DTOs\Invoice;
use LaraPardakht\Exceptions\InvalidPaymentException;

$invoice = new Invoice();

// ✅ Valid amounts
$invoice->amount(50000);  // OK: 50,000 Rials

// ❌ Invalid amounts (throws exception)
try {
    $invoice->amount(0);  // ERROR: amount <= 0
    $invoice->amount(-100);  // ERROR: negative amount
    $invoice->amount(200_000_000);  // ERROR: exceeds maximum
} catch (InvalidPaymentException $e) {
    echo "Validation failed: {$e->getMessage()}";
}

// ✅ Valid details
$invoice
    ->detail('email', 'customer@example.com')
    ->detail('mobile', '09121234567');

// ❌ Invalid details (throws exception)
try {
    $invoice->detail('email', 'not-an-email');  // ERROR: invalid email format
    $invoice->detail('mobile', '123');  // ERROR: phone too short
} catch (InvalidPaymentException $e) {
    echo "Validation failed: {$e->getMessage()}";
}
```

## Recent Security Updates

### Version 1.x
- ✅ Added explicit SSL/TLS verification (`verify(true)`) to all HTTP calls
- ✅ Added 30-second timeout to all gateway requests
- ✅ Implemented URL validation for callback and redirect URLs
- ✅ Enhanced exception handling to prevent sensitive data leakage
- ✅ Added input validation for invoice data

## Best Practices

### 1. Use HTTPS for Callbacks
Always use HTTPS for your callback URLs in the configuration:

```env
PAYMENT_CALLBACK_URL=https://yoursite.com/payment/callback
```

HTTP callbacks will be rejected by the package's URL validator.

### 2. Store Sensitive Data Securely
Never commit `.env` files to version control:

```bash
# Ensure .env is in .gitignore
echo ".env" >> .gitignore
```

### 3. Validate Callback Requests
Your callback endpoint should verify the request authenticity:

```php
// In your callback controller
Route::post('/payment/callback', function (Request $request) {
    // Verify transaction ID from request
    $transactionId = $request->input('transaction_id');
    
    // Verify against your database
    $payment = Payment::where('transaction_id', $transactionId)->firstOrFail();
    
    // Proceed with verification
    $receipt = Payment::transactionId($transactionId)->verify();
    
    return response('OK');
});
```

### 4. Monitor Failed Payment Attempts
Watch for unusual patterns in failed payments that might indicate attacks:

```php
// Log all payment failures
Payment::purchase($invoice, function ($driver, $transactionId) {
    Log::info("Purchase initiated", ['driver' => $driver, 'id' => $transactionId]);
});

try {
    $receipt = Payment::verify();
} catch (\LaraPardakht\Exceptions\InvalidPaymentException $e) {
    Log::warning("Payment verification failed", ['code' => $e->getCode()]);
}
```

### 5. Environment-Specific Configuration
Keep sandbox and production credentials separate:

```env
APP_ENV=production

# Sandbox
ZARINPAL_MERCHANT_ID=your-production-id
ZARINPAL_SANDBOX=false

# Or for testing:
# ZARINPAL_SANDBOX=true
# ZARINPAL_MERCHANT_ID=sandbox-merchant-id
```

## Reporting Security Issues

**Do not open public issues for security vulnerabilities.**

If you discover a security issue, please email `mohammad.mtajik2001@gmail.com` with:
1. Description of the vulnerability
2. Steps to reproduce
3. Potential impact
4. Suggested fix (if you have one)

We take security seriously and will respond promptly.

## Security Checklist

Before deploying to production:

- [ ] Use HTTPS for all payment callback URLs
- [ ] Set `ZARINPAL_SANDBOX=false` or `ZIBAL_SANDBOX=false` as appropriate
- [ ] Store merchant credentials in `.env`, not in code
- [ ] Enable error logging but ensure logs don't expose payment data
- [ ] Test payment flow with test credentials first
- [ ] Verify callback endpoint is accessible and handling requests correctly
- [ ] Monitor payment logs for unusual activity
- [ ] Keep Laravel and package dependencies updated

## Dependencies Security

This package depends on:
- `illuminate/support` and `illuminate/http` - Laravel's official packages
- `phpunit/phpunit` - Industry-standard PHP testing framework

All dependencies are regularly updated for security patches. Run:

```bash
composer update --security-only
```

to get the latest security updates.
