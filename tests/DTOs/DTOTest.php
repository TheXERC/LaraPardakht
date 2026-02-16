<?php

declare(strict_types=1);

use LaraPardakht\DTOs\Invoice;
use LaraPardakht\DTOs\Receipt;
use LaraPardakht\DTOs\RedirectResponse;

uses(\LaraPardakht\Tests\TestCase::class);

// ── Invoice Tests ───────────────────────────────────────────

test('invoice fluent API sets and retrieves amount', function () {
    $invoice = new Invoice();
    $invoice->amount(50000);

    expect($invoice->getAmount())->toBe(50000);
});

test('invoice fluent API is chainable', function () {
    $invoice = new Invoice();
    $result = $invoice->amount(50000)
        ->description('Test order')
        ->callbackUrl('https://example.com/callback');

    expect($result)->toBeInstanceOf(Invoice::class)
        ->and($invoice->getAmount())->toBe(50000)
        ->and($invoice->getDescription())->toBe('Test order')
        ->and($invoice->getCallbackUrl())->toBe('https://example.com/callback');
});

test('invoice generates UUID automatically', function () {
    $invoice = new Invoice();

    expect($invoice->getUuid())->toBeString()
        ->and(strlen($invoice->getUuid()))->toBeGreaterThan(0);
});

test('invoice uuid can be set manually', function () {
    $invoice = new Invoice();
    $invoice->uuid('custom-uuid-12345');

    expect($invoice->getUuid())->toBe('custom-uuid-12345');
});

test('invoice stores transaction id', function () {
    $invoice = new Invoice();
    expect($invoice->getTransactionId())->toBeNull();

    $invoice->transactionId('TXN-123');
    expect($invoice->getTransactionId())->toBe('TXN-123');
});

test('invoice stores driver preference', function () {
    $invoice = new Invoice();
    expect($invoice->getDriver())->toBeNull();

    $invoice->via('zibal');
    expect($invoice->getDriver())->toBe('zibal');
});

test('invoice detail with key-value pair', function () {
    $invoice = new Invoice();
    $invoice->detail('mobile', '09121234567');

    expect($invoice->getDetails())->toBe(['mobile' => '09121234567']);
});

test('invoice detail with array', function () {
    $invoice = new Invoice();
    $invoice->detail(['mobile' => '09121234567', 'email' => 'test@test.com']);

    expect($invoice->getDetails())->toBe([
        'mobile' => '09121234567',
        'email' => 'test@test.com',
    ]);
});

test('invoice detail chaining', function () {
    $invoice = new Invoice();
    $invoice->detail('mobile', '09121234567')
        ->detail('email', 'test@test.com');

    expect($invoice->getDetails())->toHaveCount(2);
});

// ── Receipt Tests ───────────────────────────────────────────

test('receipt holds reference id', function () {
    $receipt = new Receipt(
        referenceId: '201',
        driver: 'zarinpal',
        date: new \DateTimeImmutable('2025-01-15'),
        rawData: ['card_pan' => '5022**1234'],
    );

    expect($receipt->getReferenceId())->toBe('201');
});

test('receipt holds driver name', function () {
    $receipt = new Receipt('201', 'zarinpal', new \DateTimeImmutable());

    expect($receipt->getDriver())->toBe('zarinpal');
});

test('receipt holds date', function () {
    $date = new \DateTimeImmutable('2025-06-15T10:30:00');
    $receipt = new Receipt('201', 'zarinpal', $date);

    expect($receipt->getDate())->toBe($date);
});

test('receipt holds raw data', function () {
    $rawData = ['card_pan' => '5022**1234', 'ref_id' => 201];
    $receipt = new Receipt('201', 'zarinpal', new \DateTimeImmutable(), $rawData);

    expect($receipt->getRawData())->toBe($rawData);
});

test('receipt raw data defaults to empty array', function () {
    $receipt = new Receipt('201', 'zarinpal', new \DateTimeImmutable());

    expect($receipt->getRawData())->toBe([]);
});

// ── RedirectResponse Tests ──────────────────────────────────

test('redirect response holds URL', function () {
    $response = new RedirectResponse(url: 'https://payment.zarinpal.com/pg/StartPay/A123');

    expect($response->getUrl())->toBe('https://payment.zarinpal.com/pg/StartPay/A123');
});

test('redirect response default method is GET', function () {
    $response = new RedirectResponse(url: 'https://example.com');

    expect($response->getMethod())->toBe('GET');
});

test('redirect response can hold POST data', function () {
    $response = new RedirectResponse(
        url: 'https://bank.com/pay',
        data: ['token' => 'abc123'],
        method: 'POST',
    );

    expect($response->getMethod())->toBe('POST')
        ->and($response->getData())->toBe(['token' => 'abc123']);
});
