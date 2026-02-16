<?php

declare(strict_types=1);

namespace LaraPardakht\DTOs;

use LaraPardakht\Contracts\ReceiptInterface;

/**
 * Receipt data transfer object representing a verified payment.
 */
class Receipt implements ReceiptInterface
{
    /**
     * @param string $referenceId The payment reference ID from the gateway
     * @param string $driver The driver name that processed this payment
     * @param \DateTimeInterface $date The date of the payment
     * @param array<string, mixed> $rawData Raw response data from the gateway
     */
    public function __construct(
        protected readonly string $referenceId,
        protected readonly string $driver,
        protected readonly \DateTimeInterface $date,
        protected readonly array $rawData = [],
    ) {}

    /**
     * {@inheritdoc}
     */
    public function getReferenceId(): string
    {
        return $this->referenceId;
    }

    /**
     * {@inheritdoc}
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * {@inheritdoc}
     */
    public function getDate(): \DateTimeInterface
    {
        return $this->date;
    }

    /**
     * {@inheritdoc}
     */
    public function getRawData(): array
    {
        return $this->rawData;
    }
}
