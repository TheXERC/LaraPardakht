<?php

declare(strict_types=1);

namespace LaraPardakht\Exceptions;

use Exception;

/**
 * Base exception for all gateway-related errors.
 */
class GatewayException extends Exception
{
    /**
     * @param string $message Error message
     * @param int $code Error code
     * @param \Throwable|null $previous Previous exception
     * @param array<string, mixed> $rawData Raw response data from the gateway
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
     * Get the raw response data from the gateway.
     *
     * @return array<string, mixed>
     */
    public function getRawData(): array
    {
        return $this->rawData;
    }
}
