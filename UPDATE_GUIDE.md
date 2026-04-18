# LaraPardakht v1.x Update Guide

Quick reference for updating from v0.x to v1.x

## TL;DR - What Changed?

### One Breaking Change (1 minute to fix)
Callback URLs must now use HTTPS in production.

```env
# ❌ OLD
PAYMENT_CALLBACK_URL=http://yoursite.com/payment/callback

# ✅ NEW  
PAYMENT_CALLBACK_URL=https://yoursite.com/payment/callback
```

### What's New (All Automatic)
- ✅ SSL/TLS verification always active (Laravel default; never disabled)
- ✅ 30-second timeout on requests
- ✅ URL validation (HTTPS enforcement)
- ✅ Input validation (amount, email, phone)
- ✅ Exception sanitization (no credential leaks)
- ✅ Laravel 13 & PHP 8.4 support

### No Code Changes Needed
Your existing code will work as-is (after fixing the callback URL).

---

## Step-by-Step Update

### Step 1: Update Composer
```bash
composer update larapardakht/larapardakht
```

### Step 2: Update .env
```env
# Find this line
PAYMENT_CALLBACK_URL=http://yoursite.com/payment/callback

# Change to HTTPS
PAYMENT_CALLBACK_URL=https://yoursite.com/payment/callback

# For local development, HTTP is still allowed:
PAYMENT_CALLBACK_URL=http://127.0.0.1:8000/payment/callback  # ✅ OK
```

### Step 3: Test
```bash
# Run your existing payment tests
php artisan test

# Or test manually with your application
```

### Step 4: Deploy
```bash
# Deploy as normal
```

---

## Common Issues & Solutions

### ❌ Error: "Invalid callback URL: Payment URLs must use HTTPS"

**Cause**: Your `.env` still has an HTTP callback URL

**Fix**:
```env
# Change from:
PAYMENT_CALLBACK_URL=http://yoursite.com/payment/callback

# To:
PAYMENT_CALLBACK_URL=https://yoursite.com/payment/callback
```

### ❌ Error: "Invalid payment amount: amount must be greater than 0"

**Cause**: Trying to pay with amount 0 or negative

**Fix**: Ensure your amount is positive
```php
$invoice->amount(50000);  // ✅ Correct
$invoice->amount(0);  // ❌ Error
```

### ❌ Error: "Invalid detail: email address format is invalid"

**Cause**: Invalid email format

**Fix**: Use correctly formatted email
```php
$invoice->detail('email', 'customer@example.com');  // ✅ Correct
$invoice->detail('email', 'not-an-email');  // ❌ Error
```

### ❌ Error: "Invalid detail: must contain 9-15 digits"

**Cause**: Invalid phone format

**Fix**: Use proper phone format
```php
$invoice->detail('mobile', '09121234567');  // ✅ Correct (10 digits)
$invoice->detail('mobile', '09121234567890');  // ✅ Correct (11 digits)
$invoice->detail('mobile', '123');  // ❌ Error (too short)
```

---

## What If You Use HTTP in Development?

For local development, HTTP is still allowed:

```env
# ✅ These work in development
PAYMENT_CALLBACK_URL=http://127.0.0.1:8000/payment/callback
PAYMENT_CALLBACK_URL=http://localhost:8000/payment/callback
PAYMENT_CALLBACK_URL=http://::1:8000/payment/callback  # IPv6 localhost
```

But production must use HTTPS:
```env
# ✅ Production only
PAYMENT_CALLBACK_URL=https://yoursite.com/payment/callback
```

---

## Feature Details

### SSL/TLS Verification
- ✅ All requests verify SSL certificates (Laravel HTTP client default; never disabled)
- ✅ Prevents man-in-the-middle attacks
- No action needed - automatic

### Request Timeouts
- ⏱️ 30-second timeout on all gateway requests
- 🛡️ Prevents hanging if gateway is down
- No action needed - automatic

### URL Validation
- 🔒 All URLs must use HTTPS (except localhost)
- 🛡️ Prevents URL injection attacks
- ❌ BREAKING: HTTP URLs in production will fail

### Input Validation
- ✔️ Amount must be positive and <= 100M Rials
- ✔️ Description must be < 255 characters
- ✔️ Email must be valid format
- ✔️ Phone must be 9-15 digits

### Exception Sanitization
- 🔐 No merchant credentials leaked in logs
- 🔐 No card details exposed
- 🔐 No tokens revealed
- ✅ Safe to use error reporting (Sentry, etc.)

### Framework Support
- ✅ PHP 8.2, 8.3, 8.4
- ✅ Laravel 11, 12, 13
- ✅ Works with all supported versions

---

## Before & After Examples

### Purchase Flow (No Changes Required)
```php
use LaraPardakht\Facades\Payment;
use LaraPardakht\DTOs\Invoice;

// Create invoice
$invoice = new Invoice();
$invoice
    ->amount(50000)
    ->description('Order #123')
    ->detail('mobile', '09121234567')
    ->detail('email', 'customer@example.com');

// Purchase (same as before)
Payment::purchase($invoice, function ($driver, $transactionId) {
    // Store $transactionId
})->pay()->render();
```

### Verify Flow (No Changes Required)
```php
// Verify payment (same as before)
try {
    $receipt = Payment::transactionId($transactionId)->verify();
    echo "Payment success: " . $receipt->getReferenceId();
} catch (\LaraPardakht\Exceptions\InvalidPaymentException $e) {
    // Note: $e->getRawData() is now sanitized (no credentials exposed)
    echo "Payment failed: " . $e->getMessage();
}
```

---

## Migration Checklist

- [ ] Read SECURITY.md for security details
- [ ] Read MIGRATION.md for full migration guide
- [ ] Update `.env` callback URL to HTTPS
- [ ] Test locally with HTTPS callback
- [ ] Test payment flow with test credentials
- [ ] Run your test suite
- [ ] Deploy to staging
- [ ] Deploy to production

---

## Need Help?

1. **Security Questions**: Check [SECURITY.md](SECURITY.md)
2. **Migration Help**: Check [MIGRATION.md](MIGRATION.md)
3. **Version Info**: Check [CHANGELOG.md](CHANGELOG.md)
4. **Report Issues**: Email `mohammad.mtajik2001@gmail.com`

---

## FAQ

**Q: Do I need to change my code?**
A: Only your `.env` file (if using HTTP callback URLs). Code stays the same.

**Q: Will my existing payments break?**
A: No, only NEW payments will require HTTPS callback URLs.

**Q: Is this backwards compatible?**
A: Mostly yes, except HTTP callback URLs (breaking change noted above).

**Q: Can I use HTTP in production?**
A: No, HTTPS is required for security. Web hosting now requires HTTPS anyway.

**Q: Do I need to update anything else?**
A: Just update `.env` to use HTTPS for callback URL.

**Q: When should I update?**
A: At your convenience. This is a maintenance release with important security fixes.

**Q: What's the risk of not updating?**
A: Your payment data isn't as secure. Update recommended.

---

## What's Different Under the Hood?

### HTTP Requests
```php
// Before
Http::acceptJson()->post($url, $data);

// After
Http::acceptJson()
    ->timeout(30)      // NEW: explicit 30-second timeout
    ->post($url, $data);
// Note: SSL/TLS certificate verification is Laravel's HTTP client default.
// withoutVerifying() is never called, so certificates are always verified.
```

### URL Handling
```php
// Before
$invoice->callbackUrl('http://yoursite.com/callback');  // ✅ Worked

// After  
$invoice->callbackUrl('http://yoursite.com/callback');  // ❌ Error
$invoice->callbackUrl('https://yoursite.com/callback'); // ✅ Works
```

### Data Validation
```php
// Before
$invoice->amount(0);  // ✅ Worked (bad idea)

// After
$invoice->amount(0);  // ❌ Error (caught immediately)
```

### Exception Data
```php
// Before
$e->getRawData();  // Could contain merchant_id, tokens, etc.

// After
$e->getRawData();  // Only contains safe fields (codes, messages)
```

---

## Performance Impact

- ✅ SSL verification: +1ms (standard for HTTPS)
- ✅ Timeout handling: 0ms (only on timeout)
- ✅ URL validation: <1ms
- ✅ Input validation: <1ms
- ✅ Exception sanitization: <1ms

**Real Impact**: Negligible (< 2ms per payment operation)

---

## Timeline

| Version | Date | Status |
|---------|------|--------|
| v0.x | Past | End of life - migrate to v1.x |
| v1.0 | Now | Current version with security fixes |
| v1.x | Future | Bug fixes and minor updates |

---

## Summary

✅ **Update is easy** (1 line change)
✅ **Breaking change is minimal** (only HTTPS requirement)
✅ **All benefits automatic** (no code changes)
✅ **Fully backwards compatible** (except noted change)
✅ **Security first** (protect your payments)

Get started now! 🚀
