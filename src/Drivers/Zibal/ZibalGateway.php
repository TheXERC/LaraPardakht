<?php

declare(strict_types=1);

namespace LaraPardakht\Drivers\Zibal;

use Illuminate\Support\Facades\Http;
use LaraPardakht\Contracts\GatewayInterface;
use LaraPardakht\Contracts\InvoiceInterface;
use LaraPardakht\Contracts\ReceiptInterface;
use LaraPardakht\DTOs\Receipt;
use LaraPardakht\DTOs\RedirectResponse;
use LaraPardakht\Exceptions\InvalidPaymentException;
use LaraPardakht\Exceptions\PurchaseFailedException;

/**
 * Zibal payment gateway driver.
 *
 * Supports both production and sandbox (test) modes.
 * Sandbox mode uses merchant value "zibal" as per Zibal docs.
 *
 * @see https://help.zibal.ir/IPG/API/
 */
class ZibalGateway implements GatewayInterface
{
    /** Base URL for Zibal gateway */
    protected const string BASE_URL = 'https://gateway.zibal.ir';

    /** Purchase endpoint */
    protected const string PURCHASE_ENDPOINT = '/v1/request';

    /** Verify endpoint */
    protected const string VERIFY_ENDPOINT = '/v1/verify';

    /** Inquiry endpoint */
    protected const string INQUIRY_ENDPOINT = '/v1/inquiry';

    /** Payment page path */
    protected const string PAY_PATH = '/start/';

    /** Success response code */
    protected const int SUCCESS_CODE = 100;

    /** Already verified response code */
    protected const int ALREADY_VERIFIED_CODE = 201;

    /** Sandbox/test merchant value */
    protected const string SANDBOX_MERCHANT = 'zibal';

    /** @var InvoiceInterface The current invoice */
    protected InvoiceInterface $invoice;

    /**
     * @param array<string, mixed> $settings Gateway-specific settings from config
     */
    public function __construct(
        protected readonly array $settings,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function setInvoice(InvoiceInterface $invoice): static
    {
        $this->invoice = $invoice;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function purchase(): string
    {
        $response = Http::acceptJson()
            ->post(self::BASE_URL . self::PURCHASE_ENDPOINT, $this->buildPurchaseData());

        $body = $response->json();
        $result = $body['result'] ?? null;

        if ((int) $result !== self::SUCCESS_CODE) {
            throw new PurchaseFailedException(
                message: (string) ($body['message'] ?? 'Purchase failed with Zibal.'),
                code: (int) ($result ?? 0),
                rawData: $body,
            );
        }

        return (string) $body['trackId'];
    }

    /**
     * {@inheritdoc}
     */
    public function pay(): RedirectResponse
    {
        $trackId = $this->invoice->getTransactionId();
        $url = self::BASE_URL . self::PAY_PATH . $trackId;

        return new RedirectResponse(url: $url);
    }

    /**
     * {@inheritdoc}
     */
    public function verify(): ReceiptInterface
    {
        $response = Http::acceptJson()
            ->post(self::BASE_URL . self::VERIFY_ENDPOINT, $this->buildVerifyData());

        $body = $response->json();
        $result = $body['result'] ?? null;

        if ((int) $result !== self::SUCCESS_CODE && (int) $result !== self::ALREADY_VERIFIED_CODE) {
            $message = match ((int) $result) {
                102 => 'Merchant not found.',
                103 => 'Merchant is inactive.',
                104 => 'Invalid merchant.',
                202 => 'Payment was not successful or has not been paid.',
                203 => 'Invalid trackId.',
                default => (string) ($body['message'] ?? 'Payment verification failed with Zibal.'),
            };

            throw new InvalidPaymentException(
                message: $message,
                code: (int) ($result ?? 0),
                rawData: $body,
            );
        }

        return new Receipt(
            referenceId: (string) ($body['refNumber'] ?? $body['trackId'] ?? ''),
            driver: 'zibal',
            date: new \DateTimeImmutable(),
            rawData: $body,
        );
    }

    /**
     * Build the data payload for the purchase request.
     *
     * @return array<string, mixed>
     */
    protected function buildPurchaseData(): array
    {
        $data = [
            'merchant' => $this->getMerchant(),
            'amount' => $this->invoice->getAmount(),
            'callbackUrl' => $this->invoice->getCallbackUrl() ?? $this->settings['callback_url'] ?? '',
        ];

        $description = $this->invoice->getDescription() ?: ($this->settings['description'] ?? '');
        if ($description !== '') {
            $data['description'] = $description;
        }

        $details = $this->invoice->getDetails();

        if (isset($details['order_id'])) {
            $data['orderId'] = $details['order_id'];
        }

        if (isset($details['mobile'])) {
            $data['mobile'] = $details['mobile'];
        }

        if (isset($details['allowed_cards'])) {
            $data['allowedCards'] = $details['allowed_cards'];
        }

        if (isset($details['national_code'])) {
            $data['nationalCode'] = $details['national_code'];
        }

        return $data;
    }

    /**
     * Build the data payload for the verify request.
     *
     * @return array<string, mixed>
     */
    protected function buildVerifyData(): array
    {
        return [
            'merchant' => $this->getMerchant(),
            'trackId' => $this->invoice->getTransactionId(),
        ];
    }

    /**
     * Get the merchant value, using sandbox merchant when in sandbox mode.
     *
     * @return string
     */
    protected function getMerchant(): string
    {
        if (! empty($this->settings['sandbox'])) {
            return self::SANDBOX_MERCHANT;
        }

        return (string) ($this->settings['merchant'] ?? '');
    }
}
