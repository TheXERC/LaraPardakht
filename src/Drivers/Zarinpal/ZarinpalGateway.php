<?php

declare(strict_types=1);

namespace LaraPardakht\Drivers\Zarinpal;

use Illuminate\Support\Facades\Http;
use LaraPardakht\Contracts\GatewayInterface;
use LaraPardakht\Contracts\InvoiceInterface;
use LaraPardakht\Contracts\ReceiptInterface;
use LaraPardakht\DTOs\Receipt;
use LaraPardakht\DTOs\RedirectResponse;
use LaraPardakht\Exceptions\InvalidPaymentException;
use LaraPardakht\Exceptions\PurchaseFailedException;

/**
 * Zarinpal payment gateway driver.
 *
 * Supports both production and sandbox modes.
 *
 * @see https://www.zarinpal.com/docs/paymentGateway/
 */
class ZarinpalGateway implements GatewayInterface
{
    /** Production base URL */
    protected const string BASE_URL = 'https://payment.zarinpal.com';

    /** Sandbox base URL */
    protected const string SANDBOX_URL = 'https://sandbox.zarinpal.com';

    /** Purchase endpoint */
    protected const string PURCHASE_ENDPOINT = '/pg/v4/payment/request.json';

    /** Verify endpoint */
    protected const string VERIFY_ENDPOINT = '/pg/v4/payment/verify.json';

    /** Payment page path */
    protected const string PAY_PATH = '/pg/StartPay/';

    /** Success response code */
    protected const int SUCCESS_CODE = 100;

    /** Already verified response code */
    protected const int ALREADY_VERIFIED_CODE = 101;

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
            ->post($this->getBaseUrl() . self::PURCHASE_ENDPOINT, $this->buildPurchaseData());

        $body = $response->json();
        $code = $body['data']['code'] ?? null;

        if ($code !== self::SUCCESS_CODE) {
            $errors = $body['errors'] ?? [];
            $message = is_array($errors) && ! empty($errors)
                ? ($errors['message'] ?? 'Purchase failed with Zarinpal.')
                : 'Purchase failed with Zarinpal.';

            throw new PurchaseFailedException(
                message: (string) $message,
                code: (int) ($code ?? 0),
                rawData: $body,
            );
        }

        return (string) $body['data']['authority'];
    }

    /**
     * {@inheritdoc}
     */
    public function pay(): RedirectResponse
    {
        $authority = $this->invoice->getTransactionId();
        $url = $this->getBaseUrl() . self::PAY_PATH . $authority;

        return new RedirectResponse(url: $url);
    }

    /**
     * {@inheritdoc}
     */
    public function verify(): ReceiptInterface
    {
        $response = Http::acceptJson()
            ->post($this->getBaseUrl() . self::VERIFY_ENDPOINT, $this->buildVerifyData());

        $body = $response->json();
        $code = $body['data']['code'] ?? null;

        if ($code !== self::SUCCESS_CODE && $code !== self::ALREADY_VERIFIED_CODE) {
            $errors = $body['errors'] ?? [];
            $message = is_array($errors) && ! empty($errors)
                ? ($errors['message'] ?? 'Payment verification failed with Zarinpal.')
                : 'Payment verification failed with Zarinpal.';

            throw new InvalidPaymentException(
                message: (string) $message,
                code: (int) ($code ?? 0),
                rawData: $body,
            );
        }

        return new Receipt(
            referenceId: (string) ($body['data']['ref_id'] ?? ''),
            driver: 'zarinpal',
            date: new \DateTimeImmutable(),
            rawData: $body['data'] ?? [],
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
            'merchant_id' => $this->settings['merchant_id'] ?? '',
            'amount' => $this->invoice->getAmount(),
            'callback_url' => $this->invoice->getCallbackUrl() ?? $this->settings['callback_url'] ?? '',
            'description' => $this->invoice->getDescription() ?: ($this->settings['description'] ?? ''),
        ];

        $details = $this->invoice->getDetails();
        $metadata = [];

        if (isset($details['mobile'])) {
            $metadata['mobile'] = $details['mobile'];
        }

        if (isset($details['email'])) {
            $metadata['email'] = $details['email'];
        }

        if (isset($details['order_id'])) {
            $metadata['order_id'] = $details['order_id'];
        }

        if (! empty($metadata)) {
            $data['metadata'] = $metadata;
        }

        if (isset($this->settings['currency'])) {
            $data['currency'] = $this->settings['currency'];
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
            'merchant_id' => $this->settings['merchant_id'] ?? '',
            'amount' => $this->invoice->getAmount(),
            'authority' => $this->invoice->getTransactionId(),
        ];
    }

    /**
     * Get the base URL based on sandbox mode setting.
     *
     * @return string
     */
    protected function getBaseUrl(): string
    {
        return ! empty($this->settings['sandbox'])
            ? self::SANDBOX_URL
            : self::BASE_URL;
    }
}
