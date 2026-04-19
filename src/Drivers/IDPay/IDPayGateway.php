<?php

declare(strict_types=1);

namespace LaraPardakht\Drivers\IDPay;

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
 * IDPay payment gateway driver.
 *
 * Uses IDPay v1.1 endpoints with X-API-KEY authentication
 * and optional X-SANDBOX header in test mode.
 *
 * @see https://idpay.ir/web-service/v1.1/
 */
class IDPayGateway implements GatewayInterface
{
    /** Base URL for IDPay API v1.1 */
    protected const BASE_API_URL = 'https://api.idpay.ir/v1.1';

    /** Purchase endpoint */
    protected const PURCHASE_ENDPOINT = '/payment';

    /** Verify endpoint */
    protected const VERIFY_ENDPOINT = '/payment/verify';

    /** Verify success transaction status */
    protected const SUCCESS_STATUS = 100;

    /** Verify already-verified transaction status */
    protected const ALREADY_VERIFIED_STATUS = 101;

    /**
     * IDPay error code messages (official docs).
     *
     * @var array<int, string>
     */
    protected const ERROR_CODE_MESSAGES = [
        -1 => 'Unexpected error occurred.',
        11 => 'User is blocked.',
        12 => 'API key was not found.',
        13 => 'Request IP does not match allowed IPs for this web service.',
        14 => 'Web service is not verified yet or is under review.',
        15 => 'Requested service is unavailable.',
        21 => 'The bank account connected to this web service is not verified.',
        22 => 'Web service not found.',
        23 => 'Web service authentication failed.',
        24 => 'Related bank account is disabled.',
        31 => 'id must not be empty.',
        32 => 'order_id must not be empty.',
        33 => 'amount must not be empty.',
        34 => 'amount must be greater than the minimum allowed value.',
        35 => 'amount must be less than the maximum allowed value.',
        36 => 'amount exceeds the allowed limit.',
        37 => 'callback must not be empty.',
        38 => 'callback domain does not match the registered domain.',
        39 => 'callback is not valid.',
        41 => 'status filter must be an array of valid statuses.',
        42 => 'payment_date filter must include min and max timestamp values.',
        43 => 'settlement_date filter must include min and max timestamp values.',
        44 => 'Transaction filters are invalid.',
        51 => 'Transaction was not created.',
        52 => 'No result was found for inquiry.',
        53 => 'Payment verification is not possible.',
        54 => 'Payment verification time window has expired.',
    ];

    /**
     * IDPay transaction status messages (official docs).
     *
     * @var array<int, string>
     */
    protected const STATUS_MESSAGES = [
        1 => 'Payment has not been made.',
        2 => 'Payment failed.',
        3 => 'An error occurred.',
        4 => 'Transaction is blocked.',
        5 => 'Amount has been returned to the payer.',
        6 => 'System refund completed.',
        7 => 'Payer canceled payment.',
        8 => 'Transferred to payment gateway.',
        10 => 'Waiting for payment verification.',
        100 => 'Payment verified successfully.',
        101 => 'Payment was already verified.',
        200 => 'Amount has been settled to the payee.',
    ];

    /** @var InvoiceInterface The current invoice */
    protected InvoiceInterface $invoice;

    /** @var string|null Cached payment link from purchase response */
    protected ?string $paymentLink = null;

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
        $purchaseData = $this->buildPurchaseData();

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withHeaders($this->buildHeaders())
                ->post(self::BASE_API_URL . self::PURCHASE_ENDPOINT, $purchaseData);
        } catch (ConnectionException $exception) {
            throw new PurchaseFailedException(
                message: 'Unable to connect to IDPay purchase endpoint.',
                previous: $exception,
            );
        }

        $body = $this->normalizeBody($response->json());
        $errorCode = $this->extractErrorCode($body);

        if ($response->failed() || $errorCode !== null) {
            throw new PurchaseFailedException(
                message: $this->resolveErrorMessage(
                    body: $body,
                    errorCode: $errorCode,
                    fallback: 'Purchase failed with IDPay.',
                ),
                code: $errorCode ?? $response->status(),
                rawData: $body,
            );
        }

        $transactionId = (string) ($body['id'] ?? '');
        $link = (string) ($body['link'] ?? '');

        if ($transactionId === '' || $link === '') {
            throw new PurchaseFailedException(
                message: 'IDPay returned a successful purchase response without id or link.',
                code: $response->status(),
                rawData: $body,
            );
        }

        $this->paymentLink = $link;
        $this->invoice->detail('idpay_link', $link);

        return $transactionId;
    }

    /**
     * {@inheritdoc}
     */
    public function pay(): RedirectResponse
    {
        $paymentLink = $this->resolvePaymentLink();

        if ($paymentLink === '') {
            throw new InvalidPaymentException(
                message: 'IDPay payment link is required before calling pay. Call purchase first or provide invoice detail idpay_link.',
            );
        }

        return new RedirectResponse(url: $paymentLink);
    }

    /**
     * {@inheritdoc}
     */
    public function verify(): ReceiptInterface
    {
        $expectedAmount = $this->requirePositiveAmount();
        $verifyData = $this->buildVerifyData();

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withHeaders($this->buildHeaders())
                ->post(self::BASE_API_URL . self::VERIFY_ENDPOINT, $verifyData);
        } catch (ConnectionException $exception) {
            throw new InvalidPaymentException(
                message: 'Unable to connect to IDPay verify endpoint.',
                previous: $exception,
            );
        }

        $body = $this->normalizeBody($response->json());
        $errorCode = $this->extractErrorCode($body);

        if ($response->failed() || $errorCode !== null) {
            throw new InvalidPaymentException(
                message: $this->resolveErrorMessage(
                    body: $body,
                    errorCode: $errorCode,
                    fallback: 'Payment verification failed with IDPay.',
                ),
                code: $errorCode ?? $response->status(),
                rawData: $body,
            );
        }

        $status = $this->extractStatus($body);

        if (
            $status !== self::SUCCESS_STATUS
            && $status !== self::ALREADY_VERIFIED_STATUS
        ) {
            throw new InvalidPaymentException(
                message: $this->resolveStatusMessage(
                    status: $status,
                    fallback: 'Payment verification failed with IDPay.',
                ),
                code: $status ?? $response->status(),
                rawData: $body,
            );
        }

        $transactionId = (string) $verifyData['id'];
        $orderId = (string) $verifyData['order_id'];

        $this->assertVerifyConsistency(
            body: $body,
            expectedTransactionId: $transactionId,
            expectedOrderId: $orderId,
            expectedAmount: $expectedAmount,
            status: $status ?? 0,
        );

        $referenceId = $this->extractReferenceId($body);

        if ($referenceId === '') {
            throw new InvalidPaymentException(
                message: 'IDPay verification succeeded but no reference ID was returned.',
                code: $status ?? 0,
                rawData: $body,
            );
        }

        $receiptRawData = $body;
        $receiptRawData['already_verified'] = $status === self::ALREADY_VERIFIED_STATUS;

        return new Receipt(
            referenceId: $referenceId,
            driver: 'idpay',
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
        $callbackUrl = $this->invoice->getCallbackUrl() ?? (string) ($this->settings['callback_url'] ?? '');

        if (trim($callbackUrl) === '') {
            throw new PurchaseFailedException(
                message: 'Callback URL is required for IDPay purchase. Set callback_url config or invoice callback URL.',
            );
        }

        $data = [
            'order_id' => $this->requireOrderIdForPurchase(),
            'amount' => $this->invoice->getAmount(),
            'callback' => $callbackUrl,
        ];

        $description = $this->invoice->getDescription() ?: (string) ($this->settings['description'] ?? '');

        if ($description !== '') {
            $data['desc'] = $description;
        }

        $details = $this->invoice->getDetails();

        $name = $details['name'] ?? null;
        if (is_scalar($name) && trim((string) $name) !== '') {
            $data['name'] = (string) $name;
        }

        $phone = $details['phone'] ?? $details['mobile'] ?? null;
        if (is_scalar($phone) && trim((string) $phone) !== '') {
            $data['phone'] = (string) $phone;
        }

        $mail = $details['mail'] ?? $details['email'] ?? null;
        if (is_scalar($mail) && trim((string) $mail) !== '') {
            $data['mail'] = (string) $mail;
        }

        return $data;
    }

    /**
     * Build the data payload for the verify request.
     *
     * @return array<string, string>
     */
    protected function buildVerifyData(): array
    {
        return [
            'id' => $this->requireTransactionId(),
            'order_id' => $this->requireOrderIdForVerify(),
        ];
    }

    /**
     * Build request headers for IDPay API.
     *
     * @return array<string, string>
     */
    protected function buildHeaders(): array
    {
        $headers = [
            'X-API-KEY' => (string) ($this->settings['api_key'] ?? ''),
        ];

        if (! empty($this->settings['sandbox'])) {
            $headers['X-SANDBOX'] = '1';
        }

        return $headers;
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
     * Extract error code from IDPay response body.
     *
     * @param array<string, mixed> $body
     * @return int|null
     */
    protected function extractErrorCode(array $body): ?int
    {
        $errorCode = $body['error_code'] ?? null;

        return is_numeric($errorCode) ? (int) $errorCode : null;
    }

    /**
     * Extract transaction status from IDPay response body.
     *
     * @param array<string, mixed> $body
     * @return int|null
     */
    protected function extractStatus(array $body): ?int
    {
        $status = $body['status'] ?? null;

        return is_numeric($status) ? (int) $status : null;
    }

    /**
     * Resolve a user-friendly message from IDPay error response data.
     *
     * @param array<string, mixed> $body
     * @param int|null $errorCode
     * @param string $fallback
     * @return string
     */
    protected function resolveErrorMessage(array $body, ?int $errorCode, string $fallback): string
    {
        if ($errorCode !== null && isset(self::ERROR_CODE_MESSAGES[$errorCode])) {
            return self::ERROR_CODE_MESSAGES[$errorCode];
        }

        $errorMessage = $body['error_message'] ?? null;

        if (is_string($errorMessage) && $errorMessage !== '') {
            return $errorMessage;
        }

        $message = $body['message'] ?? null;

        if (is_string($message) && $message !== '') {
            return $message;
        }

        return $fallback;
    }

    /**
     * Resolve a user-friendly message from IDPay transaction status.
     *
     * @param int|null $status
     * @param string $fallback
     * @return string
     */
    protected function resolveStatusMessage(?int $status, string $fallback): string
    {
        if ($status !== null && isset(self::STATUS_MESSAGES[$status])) {
            return self::STATUS_MESSAGES[$status];
        }

        return $fallback;
    }

    /**
     * Ensure transaction ID is available before verify.
     *
     * @return string
     * @throws InvalidPaymentException
     */
    protected function requireTransactionId(): string
    {
        $transactionId = $this->invoice->getTransactionId();

        if (! is_string($transactionId) || trim($transactionId) === '') {
            throw new InvalidPaymentException(
                message: 'Transaction ID (id) is required before calling verify.',
            );
        }

        return $transactionId;
    }

    /**
     * Ensure order ID is available before purchase.
     *
     * @return string
     * @throws PurchaseFailedException
     */
    protected function requireOrderIdForPurchase(): string
    {
        $orderId = $this->extractOrderId();

        if ($orderId === null) {
            throw new PurchaseFailedException(
                message: 'Order ID (order_id) is required for IDPay purchase. Use invoice detail order_id.',
            );
        }

        return $orderId;
    }

    /**
     * Ensure order ID is available before verify.
     *
     * @return string
     * @throws InvalidPaymentException
     */
    protected function requireOrderIdForVerify(): string
    {
        $orderId = $this->extractOrderId();

        if ($orderId === null) {
            throw new InvalidPaymentException(
                message: 'Order ID (order_id) is required before calling verify.',
            );
        }

        return $orderId;
    }

    /**
     * Extract a non-empty order ID from invoice details.
     *
     * @return string|null
     */
    protected function extractOrderId(): ?string
    {
        $details = $this->invoice->getDetails();
        $orderId = $details['order_id'] ?? null;

        if (! is_scalar($orderId)) {
            return null;
        }

        $value = trim((string) $orderId);

        return $value === '' ? null : $value;
    }

    /**
     * Resolve the payment redirect link.
     *
     * @return string
     */
    protected function resolvePaymentLink(): string
    {
        if (is_string($this->paymentLink) && trim($this->paymentLink) !== '') {
            return $this->paymentLink;
        }

        $details = $this->invoice->getDetails();
        $paymentLink = $details['idpay_link'] ?? null;

        if (! is_string($paymentLink) || trim($paymentLink) === '') {
            return '';
        }

        return $paymentLink;
    }

    /**
     * Extract the best available reference ID from verify response.
     *
     * @param array<string, mixed> $body
     * @return string
     */
    protected function extractReferenceId(array $body): string
    {
        $trackId = $body['track_id'] ?? null;

        if (is_scalar($trackId) && (string) $trackId !== '') {
            return (string) $trackId;
        }

        $payment = $body['payment'] ?? null;

        if (is_array($payment)) {
            $paymentTrackId = $payment['track_id'] ?? null;

            if (is_scalar($paymentTrackId) && (string) $paymentTrackId !== '') {
                return (string) $paymentTrackId;
            }
        }

        return '';
    }

    /**
     * Validate verify response consistency against trusted local invoice data.
     *
     * @param array<string, mixed> $body
     * @param string $expectedTransactionId
     * @param string $expectedOrderId
     * @param int $expectedAmount
     * @param int $status
     * @return void
     * @throws InvalidPaymentException
     */
    protected function assertVerifyConsistency(
        array $body,
        string $expectedTransactionId,
        string $expectedOrderId,
        int $expectedAmount,
        int $status,
    ): void {
        $responseTransactionId = $body['id'] ?? null;

        if (
            is_scalar($responseTransactionId)
            && (string) $responseTransactionId !== ''
            && (string) $responseTransactionId !== $expectedTransactionId
        ) {
            throw new InvalidPaymentException(
                message: 'Verified payment transaction ID does not match the expected transaction ID.',
                code: $status,
                rawData: $body,
            );
        }

        $responseOrderId = $body['order_id'] ?? null;

        if (
            is_scalar($responseOrderId)
            && (string) $responseOrderId !== ''
            && (string) $responseOrderId !== $expectedOrderId
        ) {
            throw new InvalidPaymentException(
                message: 'Verified payment order ID does not match the expected invoice order ID.',
                code: $status,
                rawData: $body,
            );
        }

        $responseAmount = $body['amount'] ?? null;

        if (
            is_numeric($responseAmount)
            && (int) $responseAmount !== $expectedAmount
        ) {
            throw new InvalidPaymentException(
                message: 'Verified payment amount does not match the expected invoice amount.',
                code: $status,
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
