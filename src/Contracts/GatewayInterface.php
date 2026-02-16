<?php

declare(strict_types=1);

namespace LaraPardakht\Contracts;

use LaraPardakht\DTOs\RedirectResponse;

/**
 * Gateway contract that every payment driver must implement.
 *
 * This interface defines the three core operations of a payment gateway:
 * purchasing an invoice, redirecting to the payment page, and verifying payment.
 */
interface GatewayInterface
{
    /**
     * Set the invoice for this gateway.
     *
     * @param InvoiceInterface $invoice The invoice to process
     * @return static
     */
    public function setInvoice(InvoiceInterface $invoice): static;

    /**
     * Purchase the invoice and obtain a transaction ID from the gateway.
     *
     * @return string The transaction ID returned by the gateway
     * @throws \LaraPardakht\Exceptions\PurchaseFailedException
     */
    public function purchase(): string;

    /**
     * Generate the redirect response to the gateway payment page.
     *
     * @return RedirectResponse
     */
    public function pay(): RedirectResponse;

    /**
     * Verify a completed payment.
     *
     * @return ReceiptInterface The payment receipt with reference ID and details
     * @throws \LaraPardakht\Exceptions\InvalidPaymentException
     */
    public function verify(): ReceiptInterface;
}
