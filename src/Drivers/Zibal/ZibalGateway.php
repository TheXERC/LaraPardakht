<?php

declare(strict_types=1);

namespace LaraPardakht\Drivers\Zibal;

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
    protected const BASE_URL = 'https://gateway.zibal.ir';

    /** Purchase endpoint */
    protected const PURCHASE_ENDPOINT = '/v1/request';

    /** Verify endpoint */
    protected const VERIFY_ENDPOINT = '/v1/verify';

    /** Inquiry endpoint */
    protected const INQUIRY_ENDPOINT = '/v1/inquiry';

    /** Payment page path */
    protected const PAY_PATH = '/start/';

    /** Success response code */
    protected const SUCCESS_CODE = 100;

    /** Already verified response code */
    protected const ALREADY_VERIFIED_CODE = 201;

    /** Sandbox/test merchant value */
    protected const SANDBOX_MERCHANT = 'zibal';

    /**
     * Zibal request result code messages (official docs).
     *
     * @var array<int, string>
     */
    protected const REQUEST_RESULT_MESSAGES = [
        102 => 'Merchant not found.',
        103 => 'Merchant is inactive or gateway contract is not signed.',
        104 => 'Invalid merchant.',
        105 => 'Amount must be greater than 1,000 Rials.',
        106 => 'Invalid callbackUrl. It must start with http or https.',
        107 => 'Invalid percentMode. Only 0 or 1 is accepted.',
        108 => 'One or more beneficiaries in multiplexingInfos are invalid.',
        109 => 'One or more beneficiaries in multiplexingInfos are inactive.',
        110 => 'id=self is missing in multiplexingInfos.',
        111 => 'Amount does not match the total shares in multiplexingInfos.',
        112 => 'Insufficient wallet balance for fee deduction.',
        113 => 'Amount exceeds the maximum transaction limit.',
        114 => 'Invalid national code.',
        115 => 'Your IP address is not registered in Zibal panel.',
    ];

    /**
     * Zibal verify result code messages (official docs).
     *
     * @var array<int, string>
     */
    protected const VERIFY_RESULT_MESSAGES = [
        102 => 'Merchant not found.',
        103 => 'Merchant is inactive.',
        104 => 'Invalid merchant.',
        202 => 'Order is not paid or payment was unsuccessful.',
        203 => 'Invalid trackId.',
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
                ->post(self::BASE_URL . self::PURCHASE_ENDPOINT, $this->buildPurchaseData());
        } catch (ConnectionException $exception) {
            throw new PurchaseFailedException(
                message: 'Unable to connect to Zibal purchase endpoint.',
                previous: $exception,
            );
        }

        $body = $this->normalizeBody($response->json());
        $result = $this->extractResult($body);

        if ($response->failed() || $result !== self::SUCCESS_CODE) {
            throw new PurchaseFailedException(
                message: $this->resolveMessage(
                    body: $body,
                    result: $result,
                    resultMessages: self::REQUEST_RESULT_MESSAGES,
                    fallback: 'Purchase failed with Zibal.',
                ),
                code: $result ?? $response->status(),
                rawData: $body,
            );
        }

        $trackId = (string) ($body['trackId'] ?? '');

        if ($trackId === '') {
            throw new PurchaseFailedException(
                message: 'Zibal returned a successful purchase response without trackId.',
                code: $result ?? 0,
                rawData: $body,
            );
        }

        return $trackId;
    }

    /**
     * {@inheritdoc}
     */
    public function pay(): RedirectResponse
    {
        $trackId = $this->requireTrackId();
        $url = self::BASE_URL . self::PAY_PATH . $trackId;

        return new RedirectResponse(url: $url);
    }

    /**
     * {@inheritdoc}
     */
    public function verify(): ReceiptInterface
    {
        $expectedAmount = $this->requirePositiveAmount();

        try {
            $response = Http::acceptJson()
                ->post(self::BASE_URL . self::VERIFY_ENDPOINT, $this->buildVerifyData());
        } catch (ConnectionException $exception) {
            throw new InvalidPaymentException(
                message: 'Unable to connect to Zibal verify endpoint.',
                previous: $exception,
            );
        }

        $body = $this->normalizeBody($response->json());
        $result = $this->extractResult($body);

        if (
            $response->failed()
            || ($result !== self::SUCCESS_CODE && $result !== self::ALREADY_VERIFIED_CODE)
        ) {
            throw new InvalidPaymentException(
                message: $this->resolveMessage(
                    body: $body,
                    result: $result,
                    resultMessages: self::VERIFY_RESULT_MESSAGES,
                    fallback: 'Payment verification failed with Zibal.',
                ),
                code: $result ?? $response->status(),
                rawData: $body,
            );
        }

        $this->assertVerifyConsistency($body, $result ?? 0, $expectedAmount);

        $referenceId = $this->extractReferenceId($body);

        if ($referenceId === '') {
            throw new InvalidPaymentException(
                message: 'Zibal verification succeeded but no reference ID was returned.',
                code: $result ?? 0,
                rawData: $body,
            );
        }

        $receiptRawData = $body;
        $receiptRawData['already_verified'] = $result === self::ALREADY_VERIFIED_CODE;

        return new Receipt(
            referenceId: $referenceId,
            driver: 'zibal',
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
            'trackId' => $this->requireTrackId(),
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
     * Extract numeric result code.
     *
     * @param array<string, mixed> $body
     * @return int|null
     */
    protected function extractResult(array $body): ?int
    {
        $result = $body['result'] ?? null;

        return is_numeric($result) ? (int) $result : null;
    }

    /**
     * Resolve user-friendly message from known result codes and response body.
     *
     * @param array<string, mixed> $body
     * @param int|null $result
     * @param array<int, string> $resultMessages
     * @param string $fallback
     * @return string
     */
    protected function resolveMessage(array $body, ?int $result, array $resultMessages, string $fallback): string
    {
        if ($result !== null && isset($resultMessages[$result])) {
            return $resultMessages[$result];
        }

        $message = $body['message'] ?? null;

        if (is_string($message) && $message !== '') {
            return $message;
        }

        return $fallback;
    }

    /**
     * Ensure trackId is available before redirect/verify.
     *
     * @return string
     * @throws InvalidPaymentException
     */
    protected function requireTrackId(): string
    {
        $trackId = $this->invoice->getTransactionId();

        if (! is_string($trackId) || trim($trackId) === '') {
            throw new InvalidPaymentException(
                message: 'Transaction ID (trackId) is required before calling pay or verify.',
            );
        }

        return $trackId;
    }

    /**
     * Extract the best available reference identifier from verify response.
     *
     * @param array<string, mixed> $body
     * @return string
     */
    protected function extractReferenceId(array $body): string
    {
        $refNumber = $body['refNumber'] ?? null;

        if (is_scalar($refNumber) && (string) $refNumber !== '') {
            return (string) $refNumber;
        }

        $trackId = $body['trackId'] ?? null;

        if (is_scalar($trackId) && (string) $trackId !== '') {
            return (string) $trackId;
        }

        return '';
    }

    /**
     * Validate gateway verify response consistency against known local invoice data.
     *
     * For backward compatibility, amount is checked only when invoice amount is set (> 0)
     * and gateway response includes numeric amount.
     *
     * @param array<string, mixed> $body
     * @param int $result
     * @return void
     * @throws InvalidPaymentException
     */
    protected function assertVerifyConsistency(array $body, int $result, int $expectedAmount): void
    {
        $responseAmount = $body['amount'] ?? null;

        if (
            is_numeric($responseAmount)
            && (int) $responseAmount !== $expectedAmount
        ) {
            throw new InvalidPaymentException(
                message: 'Verified payment amount does not match the expected invoice amount.',
                code: $result,
                rawData: $body,
            );
        }

        $details = $this->invoice->getDetails();
        $expectedOrderId = $details['order_id'] ?? null;
        $responseOrderId = $body['orderId'] ?? null;

        if (
            is_scalar($expectedOrderId)
            && (string) $expectedOrderId !== ''
            && is_scalar($responseOrderId)
            && (string) $responseOrderId !== ''
            && (string) $responseOrderId !== (string) $expectedOrderId
        ) {
            throw new InvalidPaymentException(
                message: 'Verified payment order ID does not match the expected invoice order ID.',
                code: $result,
                rawData: $body,
            );
        }
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
