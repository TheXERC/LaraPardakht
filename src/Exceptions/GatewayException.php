<?php

declare(strict_types=1);

namespace LaraPardakht\Exceptions;

use Exception;

/**
 * Base exception for all gateway-related errors.
 *
 * Sanitizes sensitive data from gateway responses to prevent exposure in logs.
 */
class GatewayException extends Exception
{
    /** Fields that are safe to log from gateway responses */
    private const array SAFE_RESPONSE_FIELDS = [
        'code',
        'result',
        'status',
        'message',
        'error_message',
        'errors',
        'success',
        'timestamp',
        'date',
    ];

    /**
     * @param string $message Error message
     * @param int $code Error code
     * @param \Throwable|null $previous Previous exception
     * @param array<string, mixed> $rawData Raw response data from the gateway (will be sanitized)
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        protected readonly array $rawData = [],
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Get the sanitized raw response data from the gateway.
     *
     * Sensitive fields (like merchant IDs, tokens, card details) are removed.
     * Only non-sensitive fields are returned to prevent exposure in logs.
     *
     * @return array<string, mixed>
     */
    public function getRawData(): array
    {
        return $this->sanitizeData($this->rawData);
    }

    /**
     * Sanitize gateway response data by removing sensitive fields.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function sanitizeData(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);

            // Check if this is a safe field
            $isSafeField = false;
            foreach (self::SAFE_RESPONSE_FIELDS as $safeField) {
                if (strpos($lowerKey, strtolower($safeField)) !== false) {
                    $isSafeField = true;
                    break;
                }
            }

            if ($isSafeField) {
                // Recursively sanitize nested arrays
                if (is_array($value)) {
                    $sanitized[$key] = $this->sanitizeData($value);
                } else {
                    $sanitized[$key] = $value;
                }
            }
            // Skip sensitive fields (merchant_id, token, card_*, auth_*, secret_*, etc.)
        }

        return $sanitized;
    }
}
