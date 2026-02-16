<?php

declare(strict_types=1);

namespace LaraPardakht;

use Closure;
use LaraPardakht\Contracts\GatewayInterface;
use LaraPardakht\Contracts\InvoiceInterface;
use LaraPardakht\Contracts\ReceiptInterface;
use LaraPardakht\DTOs\Invoice;
use LaraPardakht\DTOs\RedirectResponse;
use LaraPardakht\Events\PaymentPurchased;
use LaraPardakht\Events\PaymentVerified;
use LaraPardakht\Exceptions\InvalidConfigException;

/**
 * Central payment manager that resolves drivers and orchestrates payment operations.
 *
 * This class acts as the main entry point for the package, providing a fluent API
 * for purchasing, paying, and verifying invoices through any configured gateway driver.
 */
class PaymentManager
{
    /** @var string|null Override driver name */
    protected ?string $driver = null;

    /** @var InvoiceInterface|null Current invoice */
    protected ?InvoiceInterface $invoice = null;

    /** @var GatewayInterface|null Resolved gateway driver instance */
    protected ?GatewayInterface $gateway = null;

    /** @var array<string, mixed> Runtime config overrides */
    protected array $configOverrides = [];

    /** @var string|null Override callback URL */
    protected ?string $callbackUrl = null;

    /**
     * Create a new PaymentManager instance.
     *
     * @param \Illuminate\Contracts\Foundation\Application $app
     */
    public function __construct(
        protected \Illuminate\Contracts\Foundation\Application $app,
    ) {}

    /**
     * Switch driver at runtime.
     *
     * @param string $driver Driver name (e.g. 'zarinpal', 'zibal')
     * @return static
     */
    public function via(string $driver): static
    {
        $this->driver = $driver;

        return $this;
    }

    /**
     * Override config values at runtime.
     *
     * @param string|array<string, mixed> $key Config key or array of key-value pairs
     * @param mixed $value Config value (when $key is a string)
     * @return static
     */
    public function config(string|array $key, mixed $value = null): static
    {
        if (is_array($key)) {
            $this->configOverrides = array_merge($this->configOverrides, $key);
        } else {
            $this->configOverrides[$key] = $value;
        }

        return $this;
    }

    /**
     * Set the callback URL for redirection after payment.
     *
     * @param string $url
     * @return static
     */
    public function callbackUrl(string $url): static
    {
        $this->callbackUrl = $url;

        return $this;
    }

    /**
     * Set the invoice amount directly (creates a new invoice if needed).
     *
     * @param int $amount Amount in Rials
     * @return static
     */
    public function amount(int $amount): static
    {
        $this->getOrCreateInvoice()->amount($amount);

        return $this;
    }

    /**
     * Set the transaction ID on the invoice (for verification).
     *
     * @param string $transactionId
     * @return static
     */
    public function transactionId(string $transactionId): static
    {
        $this->getOrCreateInvoice()->transactionId($transactionId);

        return $this;
    }

    /**
     * Purchase an invoice: send data to gateway and get a transaction ID.
     *
     * @param InvoiceInterface|null $invoice The invoice to purchase (or uses existing)
     * @param Closure|null $callback Called after purchase with ($driver, $transactionId)
     * @return static
     * @throws \LaraPardakht\Exceptions\PurchaseFailedException
     * @throws InvalidConfigException
     */
    public function purchase(?InvoiceInterface $invoice = null, ?Closure $callback = null): static
    {
        if ($invoice !== null) {
            $this->invoice = $invoice;
        }

        $currentInvoice = $this->getOrCreateInvoice();

        // Apply callback URL override
        if ($this->callbackUrl !== null) {
            $currentInvoice->callbackUrl($this->callbackUrl);
        }

        $gateway = $this->resolveGateway();
        $gateway->setInvoice($currentInvoice);

        $transactionId = $gateway->purchase();
        $currentInvoice->transactionId($transactionId);

        // Fire event
        event(new PaymentPurchased($currentInvoice, $transactionId, $this->getDriverName()));

        // Call user callback
        if ($callback !== null) {
            $callback($this->getDriverName(), $transactionId);
        }

        $this->gateway = $gateway;

        return $this;
    }

    /**
     * Redirect the user to the gateway payment page.
     *
     * @return RedirectResponse
     */
    public function pay(): RedirectResponse
    {
        if ($this->gateway === null) {
            $this->gateway = $this->resolveGateway();
            $this->gateway->setInvoice($this->getOrCreateInvoice());
        }

        return $this->gateway->pay();
    }

    /**
     * Verify a payment after the user returns from the gateway.
     *
     * @return ReceiptInterface
     * @throws \LaraPardakht\Exceptions\InvalidPaymentException
     * @throws InvalidConfigException
     */
    public function verify(): ReceiptInterface
    {
        $gateway = $this->resolveGateway();
        $gateway->setInvoice($this->getOrCreateInvoice());

        $receipt = $gateway->verify();

        // Fire event
        event(new PaymentVerified($receipt, $this->getDriverName()));

        return $receipt;
    }

    /**
     * Resolve the gateway driver instance.
     *
     * @return GatewayInterface
     * @throws InvalidConfigException
     */
    protected function resolveGateway(): GatewayInterface
    {
        $driverName = $this->getDriverName();
        $settings = $this->getDriverSettings($driverName);
        $gatewayClass = $this->getDriverClass($driverName);

        if (! class_exists($gatewayClass)) {
            throw new InvalidConfigException(
                "Gateway driver class [{$gatewayClass}] not found for driver [{$driverName}]."
            );
        }

        if (! is_subclass_of($gatewayClass, GatewayInterface::class)) {
            throw new InvalidConfigException(
                "Gateway driver class [{$gatewayClass}] must implement " . GatewayInterface::class . "."
            );
        }

        return new $gatewayClass($settings);
    }

    /**
     * Get the current driver name.
     *
     * @return string
     */
    protected function getDriverName(): string
    {
        return $this->driver
            ?? $this->invoice?->getDriver()
            ?? $this->app['config']->get('larapardakht.default', 'zarinpal');
    }

    /**
     * Get the settings for a specific driver, merged with runtime overrides.
     *
     * @param string $driverName
     * @return array<string, mixed>
     * @throws InvalidConfigException
     */
    protected function getDriverSettings(string $driverName): array
    {
        $settings = $this->app['config']->get("larapardakht.drivers.{$driverName}");

        if ($settings === null) {
            throw new InvalidConfigException(
                "Configuration for payment driver [{$driverName}] not found. "
                . "Please add it to your config/larapardakht.php file."
            );
        }

        return array_merge($settings, $this->configOverrides);
    }

    /**
     * Get the class name for a driver from the map config.
     *
     * @param string $driverName
     * @return string
     * @throws InvalidConfigException
     */
    protected function getDriverClass(string $driverName): string
    {
        $class = $this->app['config']->get("larapardakht.map.{$driverName}");

        if ($class === null) {
            throw new InvalidConfigException(
                "No class mapping found for payment driver [{$driverName}]. "
                . "Please add it to the 'map' section of config/larapardakht.php."
            );
        }

        return $class;
    }

    /**
     * Get or create the invoice instance.
     *
     * @return InvoiceInterface
     */
    protected function getOrCreateInvoice(): InvoiceInterface
    {
        if ($this->invoice === null) {
            $this->invoice = new Invoice();
        }

        return $this->invoice;
    }

    /**
     * Reset the manager state for a fresh operation.
     *
     * @return static
     */
    public function fresh(): static
    {
        $this->driver = null;
        $this->invoice = null;
        $this->gateway = null;
        $this->configOverrides = [];
        $this->callbackUrl = null;

        return $this;
    }
}
