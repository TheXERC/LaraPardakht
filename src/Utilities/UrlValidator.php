<?php

declare(strict_types=1);

namespace LaraPardakht\Utilities;

use LaraPardakht\Exceptions\InvalidPaymentException;

/**
 * Utility class for validating URLs used in payment processing.
 *
 * Ensures that payment callback URLs and redirect URLs are valid and secure.
 */
class UrlValidator
{
    /**
     * Validate a URL for payment processing.
     *
     * Requirements:
     * - Must be a valid URL format
     * - Must use HTTPS protocol (except for localhost in test mode)
     * - Must have a valid host
     *
     * @param string $url The URL to validate
     * @param string $context Context for error messages (e.g., 'callback', 'redirect')
     *
     * @return string The validated URL
     *
     * @throws InvalidPaymentException If URL validation fails
     */
    public static function validate(string $url, string $context = 'url'): string
    {
        // Parse the URL
        $parsed = parse_url($url);

        if ($parsed === false) {
            throw new InvalidPaymentException(
                message: "Invalid {$context}: URL could not be parsed.",
                code: 400
            );
        }

        // Check required components
        if (empty($parsed['scheme'])) {
            throw new InvalidPaymentException(
                message: "Invalid {$context}: URL must include a scheme (protocol).",
                code: 400
            );
        }

        if (empty($parsed['host'])) {
            throw new InvalidPaymentException(
                message: "Invalid {$context}: URL must include a host.",
                code: 400
            );
        }

        // Enforce HTTPS (except for localhost/127.0.0.1 for testing)
        $isLocalhost = in_array($parsed['host'], ['localhost', '127.0.0.1', '::1'], true);

        if ($parsed['scheme'] !== 'https' && ! $isLocalhost) {
            throw new InvalidPaymentException(
                message: "Invalid {$context}: Payment URLs must use HTTPS for security. "
                . "HTTP is only allowed for localhost testing.",
                code: 400
            );
        }

        // Enforce HTTPS for remote localhost (e.g., 127.0.0.1:8000 on another machine)
        if ($parsed['scheme'] !== 'https' && $isLocalhost && in_array($parsed['host'], ['127.0.0.1'], true)) {
            // Allow for local development
            return $url;
        }

        if ($parsed['scheme'] !== 'https' && $parsed['scheme'] !== 'http') {
            throw new InvalidPaymentException(
                message: "Invalid {$context}: Only HTTP and HTTPS schemes are allowed.",
                code: 400
            );
        }

        return $url;
    }

    /**
     * Validate a callback URL (must use HTTPS in production).
     *
     * @param string $url The callback URL
     *
     * @return string The validated URL
     *
     * @throws InvalidPaymentException
     */
    public static function validateCallback(string $url): string
    {
        return self::validate($url, 'callback URL');
    }

    /**
     * Validate a redirect URL (must use HTTPS in production).
     *
     * @param string $url The redirect URL
     *
     * @return string The validated URL
     *
     * @throws InvalidPaymentException
     */
    public static function validateRedirect(string $url): string
    {
        return self::validate($url, 'redirect URL');
    }
}
