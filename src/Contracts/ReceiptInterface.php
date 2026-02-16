<?php

declare(strict_types=1);

namespace LaraPardakht\Contracts;

/**
 * Receipt contract representing a verified payment result.
 */
interface ReceiptInterface
{
    /**
     * Get the payment reference ID from the gateway.
     *
     * @return string
     */
    public function getReferenceId(): string;

    /**
     * Get the driver name that processed this payment.
     *
     * @return string
     */
    public function getDriver(): string;

    /**
     * Get the date of the payment.
     *
     * @return \DateTimeInterface
     */
    public function getDate(): \DateTimeInterface;

    /**
     * Get the raw response data from the gateway.
     *
     * @return array<string, mixed>
     */
    public function getRawData(): array;
}
