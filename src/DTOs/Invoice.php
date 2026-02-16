<?php

declare(strict_types=1);

namespace LaraPardakht\DTOs;

use LaraPardakht\Contracts\InvoiceInterface;
use Ramsey\Uuid\Uuid;

/**
 * Invoice data transfer object holding payment information.
 */
class Invoice implements InvoiceInterface
{
    protected int $amount = 0;

    protected string $description = '';

    protected ?string $transactionId = null;

    /** @var array<string, mixed> */
    protected array $details = [];

    protected string $uuid;

    protected ?string $driver = null;

    protected ?string $callbackUrl = null;

    public function __construct()
    {
        $this->uuid = (string) \Illuminate\Support\Str::uuid();
    }

    /**
     * {@inheritdoc}
     */
    public function amount(int $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getAmount(): int
    {
        return $this->amount;
    }

    /**
     * {@inheritdoc}
     */
    public function description(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * {@inheritdoc}
     */
    public function transactionId(string $transactionId): static
    {
        $this->transactionId = $transactionId;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }

    /**
     * {@inheritdoc}
     */
    public function detail(string|array $key, mixed $value = null): static
    {
        if (is_array($key)) {
            $this->details = array_merge($this->details, $key);
        } else {
            $this->details[$key] = $value;
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getDetails(): array
    {
        return $this->details;
    }

    /**
     * {@inheritdoc}
     */
    public function uuid(string $uuid): static
    {
        $this->uuid = $uuid;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getUuid(): string
    {
        return $this->uuid;
    }

    /**
     * {@inheritdoc}
     */
    public function via(string $driver): static
    {
        $this->driver = $driver;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getDriver(): ?string
    {
        return $this->driver;
    }

    /**
     * {@inheritdoc}
     */
    public function callbackUrl(string $url): static
    {
        $this->callbackUrl = $url;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getCallbackUrl(): ?string
    {
        return $this->callbackUrl;
    }
}
