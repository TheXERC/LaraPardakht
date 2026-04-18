<?php

declare(strict_types=1);

namespace LaraPardakht\Drivers\Zarinpal;

use Illuminate\Http\Client\ConnectionException;
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
    protected const BASE_URL = 'https://payment.zarinpal.com';

    /** Sandbox base URL */
    protected const SANDBOX_URL = 'https://sandbox.zarinpal.com';

    /** Purchase endpoint */
    protected const PURCHASE_ENDPOINT = '/pg/v4/payment/request.json';

    /** Verify endpoint */
    protected const VERIFY_ENDPOINT = '/pg/v4/payment/verify.json';

    /** Payment page path */
    protected const PAY_PATH = '/pg/StartPay/';

    /** Success response code */
    protected const SUCCESS_CODE = 100;

    /** Already verified response code */
    protected const ALREADY_VERIFIED_CODE = 101;

    /**
     * Public/common Zarinpal response code messages (official docs).
     *
     * @var array<int, string>
     */
    protected const COMMON_CODE_MESSAGES = [
        -9 => 'Validation error.',
        -10 => 'Terminal is not valid, please check merchant_id or IP address.',
        -11 => 'Terminal is not active. Please contact support.',
        -12 => 'Too many attempts. Please try again later.',
        -13 => 'Terminal limit reached.',
        -14 => 'The callback URL domain does not match the registered terminal domain.',
        -15 => 'Terminal user is suspended. Please contact support.',
        -16 => 'Terminal user level is not valid. Please contact support.',
        -17 => 'Terminal user level is not valid. Please contact support.',
        -18 => 'The referrer address does not match the registered domain.',
        -19 => 'Terminal transactions are banned.',
    ];

    /**
     * Zarinpal purchase-specific response code messages (official docs).
     *
     * @var array<int, string>
     */
    protected const PURCHASE_CODE_MESSAGES = [
        -30 => 'Terminal does not allow floating wages.',
        -31 => 'Terminal does not allow wages. Please add a default bank account in panel.',
        -32 => 'Wages are not valid. Total floating wages exceed max amount.',
        -33 => 'Wages floating values are not valid.',
        -34 => 'Wages are not valid. Total fixed wages exceed max amount.',
        -35 => 'Wages are not valid. Floating wages reached max parts limit.',
        -36 => 'The minimum amount for floating wages should be 10,000 Rials.',
        -37 => 'One or more IBAN values for wages are inactive from bank side.',
        -38 => 'Wages need a valid IBAN in Shaparak.',
        -39 => 'Wages have an error.',
        -40 => 'Invalid extra params. expire_in is not valid.',
        -41 => 'Maximum amount is 100,000,000 tomans.',
    ];

    /**
     * Zarinpal verify-specific response code messages (official docs).
     *
     * @var array<int, string>
     */
    protected const VERIFY_CODE_MESSAGES = [
        -50 => 'Session is not valid. Amount values are not the same.',
        -51 => 'Session is not valid. Payment was not successful or session is inactive.',
        -52 => 'Unexpected error. Please contact support.',
        -53 => 'Session does not belong to this merchant_id.',
        -54 => 'Invalid authority.',
        -55 => 'Manual payment request not found.',
    ];

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
        try {
            $response = Http::acceptJson()
                ->post($this->getBaseUrl() . self::PURCHASE_ENDPOINT, $this->buildPurchaseData());
        } catch (ConnectionException $exception) {
            throw new PurchaseFailedException(
                message: 'Unable to connect to Zarinpal purchase endpoint.',
                previous: $exception,
            );
        }

        $body = $this->normalizeBody($response->json());
        $code = $this->extractCode($body);

        if ($response->failed() || $code !== self::SUCCESS_CODE) {
            throw new PurchaseFailedException(
                message: $this->resolveMessage(
                    body: $body,
                    code: $code,
                    codeMessages: self::COMMON_CODE_MESSAGES + self::PURCHASE_CODE_MESSAGES,
                    fallback: 'Purchase failed with Zarinpal.',
                ),
                code: $code ?? $response->status(),
                rawData: $body,
            );
        }

        $authority = (string) ($body['data']['authority'] ?? '');

        if ($authority === '') {
            throw new PurchaseFailedException(
                message: 'Zarinpal returned a successful purchase response without authority.',
                code: $code ?? 0,
                rawData: $body,
            );
        }

        return $authority;
    }

    /**
     * {@inheritdoc}
     */
    public function pay(): RedirectResponse
    {
        $authority = $this->requireTransactionId();
        $url = $this->getBaseUrl() . self::PAY_PATH . $authority;

        return new RedirectResponse(url: $url);
    }

    /**
     * {@inheritdoc}
     */
    public function verify(): ReceiptInterface
    {
        try {
            $response = Http::acceptJson()
                ->post($this->getBaseUrl() . self::VERIFY_ENDPOINT, $this->buildVerifyData());
        } catch (ConnectionException $exception) {
            throw new InvalidPaymentException(
                message: 'Unable to connect to Zarinpal verify endpoint.',
                previous: $exception,
            );
        }

        $body = $this->normalizeBody($response->json());
        $code = $this->extractCode($body);

        if (
            $response->failed()
            || ($code !== self::SUCCESS_CODE && $code !== self::ALREADY_VERIFIED_CODE)
        ) {
            throw new InvalidPaymentException(
                message: $this->resolveMessage(
                    body: $body,
                    code: $code,
                    codeMessages: self::COMMON_CODE_MESSAGES + self::VERIFY_CODE_MESSAGES,
                    fallback: 'Payment verification failed with Zarinpal.',
                ),
                code: $code ?? $response->status(),
                rawData: $body,
            );
        }

        $referenceId = (string) ($body['data']['ref_id'] ?? '');

        if ($referenceId === '') {
            throw new InvalidPaymentException(
                message: 'Zarinpal verification succeeded but no reference ID was returned.',
                code: $code ?? 0,
                rawData: $body,
            );
        }

        $receiptRawData = $body['data'] ?? [];

        if (! is_array($receiptRawData)) {
            $receiptRawData = [];
        }

        $receiptRawData['already_verified'] = $code === self::ALREADY_VERIFIED_CODE;

        return new Receipt(
            referenceId: $referenceId,
            driver: 'zarinpal',
            date: new \DateTimeImmutable(),
            rawData: $receiptRawData,
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
            'amount' => $this->requirePositiveAmount(),
            'authority' => $this->requireTransactionId(),
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

    /**
     * Normalize decoded JSON body to a safe array.
     *
     * @param mixed $body
     * @return array<string, mixed>
     */
    protected function normalizeBody(mixed $body): array
    {
        return is_array($body) ? $body : [];
    }

    /**
     * Extract gateway response code from known fields.
     *
     * @param array<string, mixed> $body
     * @return int|null
     */
    protected function extractCode(array $body): ?int
    {
        $dataCode = $body['data']['code'] ?? null;

        if (is_numeric($dataCode)) {
            return (int) $dataCode;
        }

        $errors = $body['errors'] ?? [];
        $errorCode = is_array($errors) ? ($errors['code'] ?? null) : null;

        return is_numeric($errorCode) ? (int) $errorCode : null;
    }

    /**
     * Resolve human-friendly error message from code mappings and response body.
     *
     * @param array<string, mixed> $body
     * @param int|null $code
     * @param array<int, string> $codeMessages
     * @param string $fallback
     * @return string
     */
    protected function resolveMessage(array $body, ?int $code, array $codeMessages, string $fallback): string
    {
        if ($code !== null && isset($codeMessages[$code])) {
            return $codeMessages[$code];
        }

        $errors = $body['errors'] ?? [];

        if (is_array($errors)) {
            $errorMessage = $errors['message'] ?? ($errors[0]['message'] ?? null);

            if (is_string($errorMessage) && $errorMessage !== '') {
                return $errorMessage;
            }
        }

        $data = $body['data'] ?? [];
        $dataMessage = is_array($data) ? ($data['message'] ?? null) : null;

        if (is_string($dataMessage) && $dataMessage !== '') {
            return $dataMessage;
        }

        $topLevelMessage = $body['message'] ?? null;

        if (is_string($topLevelMessage) && $topLevelMessage !== '') {
            return $topLevelMessage;
        }

        return $fallback;
    }

    /**
     * Ensure transaction ID is present before redirect/verify operations.
     *
     * @return string
     * @throws InvalidPaymentException
     */
    protected function requireTransactionId(): string
    {
        $authority = $this->invoice->getTransactionId();

        if (! is_string($authority) || trim($authority) === '') {
            throw new InvalidPaymentException(
                message: 'Transaction ID (authority) is required before calling pay or verify.',
            );
        }

        return $authority;
    }

    /**
     * Ensure invoice amount is set before verify.
     *
     * @return int
     * @throws InvalidPaymentException
     */
    protected function requirePositiveAmount(): int
    {
        $amount = $this->invoice->getAmount();

        if ($amount <= 0) {
            throw new InvalidPaymentException(
                message: 'Invoice amount is required and must be greater than zero before calling verify.',
            );
        }

        return $amount;
    }
}
