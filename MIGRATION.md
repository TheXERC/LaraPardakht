# Migration Guide

This guide helps you upgrade LaraPardakht to the latest version with security enhancements.

## From v0.x to v1.x

### Breaking Changes

#### 1. Callback URLs Must Use HTTPS

**What Changed:** Payment callback URLs now require HTTPS in production environments for security.

**What to do:**

1. Open your `.env` file
2. Update `PAYMENT_CALLBACK_URL` to use HTTPS:

```env
# ❌ Before (no longer supported in production)
PAYMENT_CALLBACK_URL=http://yoursite.com/payment/callback

# ✅ After (required for production)
PAYMENT_CALLBACK_URL=https://yoursite.com/payment/callback
```

**For Development/Testing:**

HTTP is still allowed for localhost addresses:

```env
# ✅ OK for local development
PAYMENT_CALLBACK_URL=http://127.0.0.1:8000/payment/callback
PAYMENT_CALLBACK_URL=http://localhost:8000/payment/callback
```

**Error Message:**

If you still have HTTP configured, you'll see:

```
Invalid callback URL: Payment URLs must use HTTPS for security.
HTTP is only allowed for localhost testing.
```

#### 2. Request Timeout Added

**What Changed:** All HTTP requests to payment gateways now timeout after 30 seconds.

**What to do:**

Usually, nothing. If you experience timeout errors:

1. Verify your gateway credentials in `.env` are correct
2. Check your server has stable internet connectivity
3. Check if the payment gateway services are operational

**Error Example:**

```
Connection timeout after 30 seconds waiting for payment gateway response
```

### Non-Breaking Changes

These changes are fully backwards compatible:

✅ **No changes needed for:**
- Gateway credentials
- Driver configuration
- Sandbox settings
- Event listeners
- Invoice & Receipt interfaces
- Redirect response methods
- Purchase and verify workflows

### Deployment Checklist

Before deploying to staging/production:

- [ ] Update `.env` to use HTTPS for `PAYMENT_CALLBACK_URL`
- [ ] Test payment flow with test gateway credentials
- [ ] Verify callback endpoint is accessible and responding
- [ ] Check server has outbound HTTPS connectivity
- [ ] Review `SECURITY.md` for security best practices
- [ ] Monitor logs for timeout errors after deployment

### Rollback Instructions

If you need to rollback to v0.x:

```bash
composer require larapardakht/larapardakht:"0.x"
```

Then revert `.env` changes and redeploy.

### Support

Issues or questions? 

1. Check the Security section in [SECURITY.md](SECURITY.md)
2. Review the documentation in [README.md](README.md)
3. Report issues to: `mohammad.mtajik2001@gmail.com`

---

## Framework Compatibility

LaraPardakht v1.x supports:

| Framework | Version | Status |
|-----------|---------|--------|
| Laravel | 11 | ✅ Supported |
| Laravel | 12 | ✅ Supported |
| Laravel | 13 | ✅ Supported (preview) |
| PHP | 8.2 | ✅ Supported |
| PHP | 8.3 | ✅ Supported |
| PHP | 8.4 | ✅ Supported (preview) |

Upgrade your Laravel and PHP versions as needed. Security patches are backported to supported versions.
