<?php

declare(strict_types=1);

namespace LaraPardakht\Facades;

use Illuminate\Support\Facades\Facade;
use LaraPardakht\Contracts\InvoiceInterface;
use LaraPardakht\Contracts\ReceiptInterface;
use LaraPardakht\DTOs\RedirectResponse;
use LaraPardakht\PaymentManager;

/**
 * Payment facade for convenient static access to PaymentManager.
 *
 * @method static PaymentManager via(string $driver)
 * @method static PaymentManager config(string|array $key, mixed $value = null)
 * @method static PaymentManager callbackUrl(string $url)
 * @method static PaymentManager amount(int $amount)
 * @method static PaymentManager transactionId(string $transactionId)
 * @method static PaymentManager purchase(?InvoiceInterface $invoice = null, ?\Closure $callback = null)
 * @method static RedirectResponse pay()
 * @method static ReceiptInterface verify()
 * @method static PaymentManager fresh()
 *
 * @see \LaraPardakht\PaymentManager
 */
class Payment extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return PaymentManager::class;
    }
}
