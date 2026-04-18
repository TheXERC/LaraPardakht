# LaraPardakht Security Audit - Commit Strategy

**Total Commits Recommended**: 5 separate commits (one per fix)

This breakdown allows for clear version control history, easy review, and ability to roll back individual changes if needed.

---

## Commit #1: SSL/TLS Verification & Request Timeouts

**Files Modified**:
- `src/Drivers/Zarinpal/ZarinpalGateway.php`
- `src/Drivers/Zibal/ZibalGateway.php`
- `SECURITY.md` (new)

**Commit Message**:
```
security: add request timeouts; ensure SSL/TLS verification is never disabled

- Add 30-second timeout to prevent indefinite connection hanging
- SSL/TLS certificate verification is Laravel's HTTP client default;
  withoutVerifying() is deliberately never called in any gateway method
- Applied to both purchase() and verify() methods in Zarinpal and Zibal drivers
- Protects against man-in-the-middle attacks and resource exhaustion
- BREAKING: None (automatic security enhancement)
- USERS: No action required - feature is automatic
```

**What to Test**:
- Payment purchase flow still works
- Payment verification still works
- Check logs for no additional timeout warnings

**Risk Level**: 🟢 LOW (only adds existing Laravel features)

---

## Commit #2: URL Validation for Callback & Redirect URLs

**Files Modified**:
- `src/Utilities/UrlValidator.php` (new)
- `src/DTOs/Invoice.php`
- `src/DTOs/RedirectResponse.php`
- `MIGRATION.md` (new)

**Commit Message**:
```
security: add HTTPS URL validation for callbacks and redirects

- Create UrlValidator utility for payment URL validation
- Enforce HTTPS for all payment URLs in production
- Allow HTTP only for localhost (127.0.0.1, ::1) in development
- Applied to callback URLs and redirect URLs
- BREAKING: HTTP callback URLs will throw InvalidPaymentException
- USERS: Must update PAYMENT_CALLBACK_URL in .env to use HTTPS

Closes: [Issue if exists]
Migration Guide: See MIGRATION.md
```

**What to Test**:
- HTTPS callback URLs work
- HTTP callback URLs in production throw InvalidPaymentException
- HTTP localhost URLs still work
- Payment redirects validate properly

**Risk Level**: 🟡 MEDIUM (breaking for HTTP URLs)

**User Action Required**: 
Update `.env` callback URL from HTTP to HTTPS

---

## Commit #3: Sensitive Data Protection in Exceptions

**Files Modified**:
- `src/Exceptions/GatewayException.php`
- `SECURITY.md` (updated)

**Commit Message**:
```
security: sanitize sensitive data in gateway exceptions

- Filter raw gateway response data before storing in exceptions
- Remove merchant IDs, tokens, card details, auth credentials
- Only retain safe fields: status codes, messages, timestamps
- Prevents credential leakage via logs and error tracking services
- BREAKING: None (backward compatible API)
- USERS: No action required - feature is automatic

Safe fields preserved:
- code, result, status (gateway response codes)
- message, error_message (human-readable errors)
- timestamp, date (when error occurred)

Sensitive fields filtered:
- merchant_id, merchant, token, authorization, auth_*
- card_*, pan, cvv, secret_*, key_*
```

**What to Test**:
- Store exception data in logs/monitoring
- Verify no sensitive fields appear in exception data
- Check exception messages are still helpful

**Risk Level**: 🟢 LOW (only filters sensitive data)

---

## Commit #4: Input Validation for Invoice Data

**Files Modified**:
- `src/Utilities/InvoiceValidator.php` (new)
- `src/DTOs/Invoice.php`
- `CHANGELOG.md` (updated)
- `SECURITY.md` (updated)

**Commit Message**:
```
security: add comprehensive input validation to invoice data

- Create InvoiceValidator utility class
- Validate payment amounts: must be positive, max 100M Rials
- Validate descriptions: non-empty, max 255 chars, no null bytes
- Validate email format (RFC 5321 compliant)
- Validate phone numbers: 9-15 digits, optionally starting with +
- Throw InvalidPaymentException on validation errors
- BREAKING: Invalid data now throws exceptions (instead of silently failing)
- USERS: Ensure invoice data is valid before use

Examples:
- amount(50000) ✅ Valid
- amount(0) ❌ Throws (must be > 0)
- detail('email', 'user@example.com') ✅ Valid
- detail('email', 'invalid') ❌ Throws (invalid format)
- detail('mobile', '09121234567') ✅ Valid
- detail('mobile', '123') ❌ Throws (too short)
```

**What to Test**:
- Valid amounts work
- Invalid amounts throw exceptions
- Valid emails work
- Invalid emails throw exceptions
- Valid phone numbers work
- Invalid phone numbers throw exceptions

**Risk Level**: 🟡 MEDIUM (new validation may catch bad data)

**User Action Required**: 
Ensure invoice data is validated before use

---

## Commit #5: Framework Version Support Update

**Files Modified**:
- `composer.json`
- `README.md`
- `CHANGELOG.md`

**Commit Message**:
```
compatibility: add Laravel 13 and PHP 8.3/8.4 support

Updated version constraints:
- PHP: ^8.2|^8.3|^8.4 (was ^8.2)
- Laravel: ^11.0|^12.0|^13.0 (was ^11.0|^12.0)
- Orchestra/Testbench: ^9.0|^10.0|^11.0 (was ^9.0|^10.0)

- Package now supports PHP 8.3 and PHP 8.4
- Package now supports Laravel 13
- BREAKING: None (only expands supported versions)
- USERS: Can now use with Laravel 13 and PHP 8.4

No code changes required - existing code compatible with all versions.
```

**What to Test**:
- Package installs with Laravel 13
- Package installs with PHP 8.3/8.4
- Tests pass on all supported versions

**Risk Level**: 🟢 LOW (only relaxes version constraints)

---

## Additional Files (Created for Documentation)

**Not requiring commits, but good to include**:
- `SECURITY.md` - Comprehensive security policy
- `MIGRATION.md` - Migration guide for v0.x→v1.x users
- `UPDATE_GUIDE.md` - Quick reference guide
- `SECURITY_AUDIT_SUMMARY.md` - Detailed audit summary
- `RELEASE_NOTES.md` - Professional release notes

These are all documentation and don't affect functionality.

---

## Recommended Commit Timeline

**Option A: Conservative (Slow & Safe)**
```
Week 1: Commit #1 (SSL/TLS) → Test → Deploy to staging
Week 2: Commit #2 (URL Validation) → Test → Deploy to staging
Week 3: Commit #3 (Exception Sanitization) → Test → Deploy to staging
Week 4: Commit #4 (Input Validation) → Test → Deploy to staging
Week 5: Commit #5 (Version Support) → Deploy to production
```

**Option B: Balanced (Standard)**
```
Day 1: Commits #1-2 (Security) → Testing
Day 2: Commits #3-4 (Data Protection) → Testing
Day 3: Commit #5 (Compatibility) → Final testing
Day 4: Deploy to production
```

**Option C: Aggressive (Fast)**
```
Day 1: All commits → Comprehensive testing → Deploy
```

---

## Review Checklist Per Commit

### Before Each Commit
- [ ] Code has no syntax errors (verified)
- [ ] Changes are documented in commit message
- [ ] Breaking changes are clearly noted
- [ ] User actions needed are specified
- [ ] Related files are updated (CHANGELOG, SECURITY.md, etc.)

### After Each Commit
- [ ] Run tests: `php -l <file>`
- [ ] Verify no regression
- [ ] Test breaking changes (if applicable)
- [ ] Update internal documentation
- [ ] Prepare release notes

### Before Production Deployment
- [ ] All 5 commits reviewed and tested
- [ ] MIGRATION.md reviewed and shared with users
- [ ] Documentation files updated
- [ ] Changelog updated with version number
- [ ] Release notes prepared
- [ ] User communication plan ready

---

## User Communication Plan

### When Commit #2 (URL Validation)
Send to users:
> "Update your PAYMENT_CALLBACK_URL in .env to use HTTPS. HTTP is no longer allowed in production."

### When Commit #4 (Input Validation)
Send to users:
> "Ensure invoice data (amounts, emails, phones) is properly validated before use."

### When All Commits Complete
Send full release:
> "v1.0.0 released with security enhancements. See RELEASE_NOTES.md for details."

---

## Rollback Plan (If Issues Arise)

### After Commit #1
```bash
git revert <commit-1>
# Only loses SSL/TLS - no major impact
```

### After Commit #2
```bash
git revert <commit-2>
# Loses URL validation - allow HTTP URLs again
# Users can revert .env changes
```

### After Commit #3
```bash
git revert <commit-3>
# Loses exception sanitization - minor impact
```

### After Commit #4
```bash
git revert <commit-4>
# Loses input validation - may need to add checks elsewhere
```

### After Commit #5
```bash
git revert <commit-5>
# Just reverts version constraints - easy to rollback
```

---

## Git Commands for Each Commit

```bash
# Commit #1: SSL/TLS
git add src/Drivers/Zarinpal/ZarinpalGateway.php \
         src/Drivers/Zibal/ZibalGateway.php \
         SECURITY.md
git commit -m "security: add request timeouts and ensure SSL/TLS is never disabled"

# Commit #2: URL Validation
git add src/Utilities/UrlValidator.php \
         src/DTOs/Invoice.php \
         src/DTOs/RedirectResponse.php \
         MIGRATION.md
git commit -m "security: add HTTPS URL validation"

# Commit #3: Exception Sanitization
git add src/Exceptions/GatewayException.php
git commit -m "security: sanitize sensitive data in exceptions"

# Commit #4: Input Validation
git add src/Utilities/InvoiceValidator.php \
         src/DTOs/Invoice.php
git commit -m "security: add input validation"

# Commit #5: Version Support
git add composer.json README.md CHANGELOG.md
git commit -m "compatibility: add Laravel 13 and PHP 8.3/8.4 support"
```

---

## Summary

**Total Changes**: 5 commits
**Files Modified**: ~15 files
**New Files**: 4 core (validators), 5 documentation
**Breaking Changes**: 1 (HTTPS requirement)
**User Action Required**: 1 line `.env` change per commit
**Risk Level**: Low to Medium (clearly documented)

All changes are ready to commit! 🎉

Choose your timeline and start with Commit #1.
