<?php

declare(strict_types=1);

namespace LaraPardakht\Contracts;

/**
 * Invoice contract representing payment data to be sent to a gateway.
 */
interface InvoiceInterface
{
    /**
     * Set the payment amount in Rials.
     *
     * @param int $amount Amount in Rials
     * @return static
     */
    public function amount(int $amount): static;

    /**
     * Get the payment amount.
     *
     * @return int
     */
    public function getAmount(): int;

    /**
     * Set a description for this payment.
     *
     * @param string $description
     * @return static
     */
    public function description(string $description): static;

    /**
     * Get the payment description.
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * Set the transaction ID (usually set after purchase).
     *
     * @param string $transactionId
     * @return static
     */
    public function transactionId(string $transactionId): static;

    /**
     * Get the transaction ID.
     *
     * @return string|null
     */
    public function getTransactionId(): ?string;

    /**
     * Attach custom detail(s) to the invoice.
     *
     * @param string|array<string, mixed> $key
     * @param mixed $value
     * @return static
     */
    public function detail(string|array $key, mixed $value = null): static;

    /**
     * Get all custom details.
     *
     * @return array<string, mixed>
     */
    public function getDetails(): array;

    /**
     * Set the invoice UUID.
     *
     * @param string $uuid
     * @return static
     */
    public function uuid(string $uuid): static;

    /**
     * Get the invoice UUID.
     *
     * @return string
     */
    public function getUuid(): string;

    /**
     * Set the driver name for this invoice.
     *
     * @param string $driver
     * @return static
     */
    public function via(string $driver): static;

    /**
     * Get the driver name.
     *
     * @return string|null
     */
    public function getDriver(): ?string;

    /**
     * Set the callback URL.
     *
     * @param string $url
     * @return static
     */
    public function callbackUrl(string $url): static;

    /**
     * Get the callback URL.
     *
     * @return string|null
     */
    public function getCallbackUrl(): ?string;
}
