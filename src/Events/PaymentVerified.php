<?php

declare(strict_types=1);

namespace LaraPardakht\Events;

use Illuminate\Foundation\Events\Dispatchable;
use LaraPardakht\Contracts\ReceiptInterface;

/**
 * Fired after a payment has been successfully verified.
 */
class PaymentVerified
{
    use Dispatchable;

    /**
     * @param ReceiptInterface $receipt The verified payment receipt
     * @param string $driver The driver name used
     */
    public function __construct(
        public readonly ReceiptInterface $receipt,
        public readonly string $driver,
    ) {}
}
