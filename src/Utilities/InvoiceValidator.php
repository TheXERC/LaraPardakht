<?php

declare(strict_types=1);

namespace LaraPardakht\Utilities;

use LaraPardakht\Exceptions\InvalidPaymentException;

/**
 * Utility class for validating invoice data before sending to payment gateways.
 *
 * Ensures that payment data is properly formatted and valid.
 */
class InvoiceValidator
{
    /**
     * Validate the payment amount.
     *
     * Requirements:
     * - Must be a positive integer
     * - Must be greater than 0
     * - Amount is in Rials (Iranian currency)
     *
     * @param int $amount The amount in Rials
     *
     * @return int The validated amount
     *
     * @throws InvalidPaymentException If validation fails
     */
    public static function validateAmount(int $amount): int
    {
        if ($amount <= 0) {
            throw new InvalidPaymentException(
                message: "Invalid payment amount: amount must be greater than 0. Amount provided: {$amount}",
                code: 400
            );
        }

        // Check for reasonable upper limit (100 million Rials = ~$2380 USD)
        // Adjust this based on your business needs
        $maxAmount = 100_000_000;
        if ($amount > $maxAmount) {
            throw new InvalidPaymentException(
                message: "Invalid payment amount: amount exceeds maximum limit of {$maxAmount} Rials.",
                code: 400
            );
        }

        return $amount;
    }

    /**
     * Validate the payment description.
     *
     * Requirements:
     * - Must not be empty when set
     * - Maximum 255 characters
     * - No null bytes
     *
     * @param string $description The payment description
     *
     * @return string The validated description
     *
     * @throws InvalidPaymentException If validation fails
     */
    public static function validateDescription(string $description): string
    {
        if (empty(trim($description))) {
            throw new InvalidPaymentException(
                message: "Invalid payment description: description cannot be empty.",
                code: 400
            );
        }

        if (strlen($description) > 255) {
            throw new InvalidPaymentException(
                message: "Invalid payment description: description must not exceed 255 characters.",
                code: 400
            );
        }

        // Check for null bytes
        if (strpos($description, "\0") !== false) {
            throw new InvalidPaymentException(
                message: "Invalid payment description: description contains invalid characters.",
                code: 400
            );
        }

        return $description;
    }

    /**
     * Validate an email address.
     *
     * Requirements:
     * - Valid email format
     * - Not longer than 254 characters (RFC 5321)
     *
     * @param string $email The email address
     * @param string $context Context for error messages
     *
     * @return string The validated email
     *
     * @throws InvalidPaymentException If validation fails
     */
    public static function validateEmail(string $email, string $context = 'email'): string
    {
        if (strlen($email) > 254) {
            throw new InvalidPaymentException(
                message: "Invalid {$context}: email address exceeds maximum length.",
                code: 400
            );
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidPaymentException(
                message: "Invalid {$context}: email address format is invalid.",
                code: 400
            );
        }

        return $email;
    }

    /**
     * Validate a phone number.
     *
     * Requirements:
     * - Must be 10-15 characters (ITU-T E.164 standard)
     * - Can only contain digits and optional leading +
     * - Iranian numbers typically: 09XX XXXX XXXX (10 digits) or +98 9XX XXXX XXXX
     *
     * @param string $phone The phone number
     * @param string $context Context for error messages
     *
     * @return string The validated phone number
     *
     * @throws InvalidPaymentException If validation fails
     */
    public static function validatePhoneNumber(string $phone, string $context = 'phone number'): string
    {
        // Remove spaces and hyphens for validation
        $cleaned = preg_replace('/[\s\-]/', '', $phone);

        if ($cleaned === null) {
            throw new InvalidPaymentException(
                message: "Invalid {$context}: validation failed.",
                code: 400
            );
        }

        // Check format: optional +, followed by 9-15 digits
        if (! preg_match('/^\+?[0-9]{9,15}$/', $cleaned)) {
            throw new InvalidPaymentException(
                message: "Invalid {$context}: must contain 9-15 digits and optionally start with +.",
                code: 400
            );
        }

        // For Iranian numbers specifically (optional check)
        // Allow: 09XX XXXX XXXX or +98 9XX XXXX XXXX
        if (strlen($cleaned) === 11 && ! str_starts_with($cleaned, '0')) {
            throw new InvalidPaymentException(
                message: "Invalid {$context}: Iranian mobile numbers must start with 0 or +98.",
                code: 400
            );
        }

        return $phone;
    }

    /**
     * Validate invoice details (custom key-value pairs).
     *
     * Ensures that detail keys and values are properly formatted.
     *
     * @param array<string, mixed> $details The detail pairs to validate
     *
     * @return array<string, mixed> The validated details
     *
     * @throws InvalidPaymentException If validation fails
     */
    public static function validateDetails(array $details): array
    {
        foreach ($details as $key => $value) {
            // Validate key
            if (! is_string($key) || empty($key)) {
                throw new InvalidPaymentException(
                    message: "Invalid invoice detail: keys must be non-empty strings.",
                    code: 400
                );
            }

            if (strlen($key) > 100) {
                throw new InvalidPaymentException(
                    message: "Invalid invoice detail: key '{$key}' exceeds maximum length of 100 characters.",
                    code: 400
                );
            }

            // Validate specific detail types
            if ($key === 'email' && is_string($value)) {
                self::validateEmail($value, 'detail email');
            } elseif ($key === 'mobile' && is_string($value)) {
                self::validatePhoneNumber($value, 'detail mobile');
            } elseif (is_array($value)) {
                // Recursively validate nested arrays
                self::validateDetails($value);
            }
        }

        return $details;
    }
}
