<?php

declare(strict_types=1);

namespace LaraPardakht\Events;

use Illuminate\Foundation\Events\Dispatchable;
use LaraPardakht\Contracts\InvoiceInterface;

/**
 * Fired after an invoice has been successfully purchased (transaction ID obtained).
 */
class PaymentPurchased
{
    use Dispatchable;

    /**
     * @param InvoiceInterface $invoice The purchased invoice
     * @param string $transactionId The transaction ID from the gateway
     * @param string $driver The driver name used
     */
    public function __construct(
        public readonly InvoiceInterface $invoice,
        public readonly string $transactionId,
        public readonly string $driver,
    ) {}
}
