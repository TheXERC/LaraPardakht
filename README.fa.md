# LaraPardakht

یک پکیج مدرن و توسعه‌پذیر برای یکپارچه‌سازی درگاه‌های پرداخت در Laravel 11، 12 و 13 با پشتیبانی از ارائه‌دهنده‌های پرداخت ایرانی.

## ویژگی‌ها

- **معماری مبتنی بر درایور** — افزودن درگاه‌های جدید بدون تغییر هسته پکیج
- **API روان (Fluent)** — رابط زنجیره‌ای و خوانا برای خرید، پرداخت و تایید
- **پشتیبانی از Sandbox/Test** — هر درایور به صورت پیش‌فرض حالت تست را پشتیبانی می‌کند
- **رویدادها** — ارسال رویداد بعد از خرید و اولین تایید موفق پرداخت
- **پیکربندی در زمان اجرا** — تغییر درایور و بازنویسی تنظیمات در لحظه
- **استثناهای تایپ‌شده** — کلاس‌های خطای مجزا برای سناریوهای مختلف شکست

## درگاه‌های پشتیبانی‌شده

| درگاه | حالت عادی | حالت Sandbox |
|---------|:-----------:|:------------:|
| [زرین‌پال](https://www.zarinpal.com/) | ✅ | ✅ |
| [زیبال](https://zibal.ir/) | ✅ | ✅ |

درگاه‌های بیشتری در راه هستند! همچنین می‌توانید [درایور سفارشی](#ساخت-درایور-سفارشی) بسازید.

## پیش‌نیازها

- PHP 8.2+
- Laravel 11، 12 یا 13

## نصب

```bash
composer require larapardakht/larapardakht
```

### انتشار فایل تنظیمات

```bash
php artisan vendor:publish --tag=larapardakht-config
```

با این دستور فایل `config/larapardakht.php` در اپلیکیشن شما ساخته می‌شود.

## پیکربندی

اطلاعات درگاه را در `.env` تنظیم کنید:

```env
PAYMENT_GATEWAY=zarinpal

# Zarinpal
ZARINPAL_MERCHANT_ID=your-merchant-id-here
ZARINPAL_SANDBOX=false

# Zibal
ZIBAL_MERCHANT=your-zibal-merchant
ZIBAL_SANDBOX=false

# Shared
PAYMENT_CALLBACK_URL=https://yoursite.com/payment/callback
```

## استفاده

### خرید و ریدایرکت

```php
use LaraPardakht\Facades\Payment;
use LaraPardakht\DTOs\Invoice;

$invoice = new Invoice();
$invoice->amount(50000)
    ->description('Order #123')
    ->detail('mobile', '09121234567')
    ->detail('email', 'customer@example.com');

return Payment::purchase($invoice, function ($driver, $transactionId) {
    // شناسه تراکنش را در ذخیره‌سازی خودتان نگه‌داری کنید (مثلا رکورد سفارش)
})->pay()->render();
```

### تایید پرداخت

```php
use LaraPardakht\Facades\Payment;
use LaraPardakht\Exceptions\InvalidPaymentException;

try {
    $receipt = Payment::amount(50000)
        ->transactionId($transactionId)
        ->verify();

    // پرداخت موفق بود
    echo $receipt->getReferenceId();
    echo $receipt->getDriver();

} catch (InvalidPaymentException $e) {
    // تایید پرداخت ناموفق بود
    echo $e->getMessage();
}
```

### تغییر درایور در زمان اجرا

```php
Payment::via('zibal')->purchase($invoice, function ($driver, $transactionId) {
    // ...
});
```

### بازنویسی تنظیمات در زمان اجرا

```php
Payment::config('merchant_id', 'another-merchant-id')->purchase($invoice);

// یا چند مقدار با هم:
Payment::config([
    'merchant_id' => 'another-merchant-id',
    'sandbox' => true,
])->purchase($invoice);
```

### بازنویسی Callback URL

```php
Payment::callbackUrl('https://yoursite.com/custom-callback')
    ->purchase($invoice);
```

### دریافت داده ریدایرکت به صورت JSON

```php
$redirect = Payment::purchase($invoice)->pay();
return $redirect->toJson();
```

## نکات امنیت و پایداری

- `PaymentManager` در Container به صورت scoped ثبت شده است (در چرخه request/job) تا نشت state بین درخواست‌ها در workerهای طولانی‌مدت رخ ندهد.
- کش Facade برای `Payment` غیرفعال است تا هر بار از scope فعلی Container resolve شود.
- متد `pay()` وجود شناسه تراکنش را بررسی می‌کند و در نبود آن `InvalidPaymentException` پرتاب می‌کند.
- متد `verify()` وجود شناسه مرجع را در پاسخ موفق بررسی می‌کند و در نبود آن `InvalidPaymentException` پرتاب می‌کند.
- متد `verify()` قبل از تماس با درگاه، مقدار مثبت برای مبلغ فاکتور (`amount > 0`) را اجباری می‌کند.
- در `Zibal` هنگام verify بررسی سازگاری با داده‌های محلی انجام می‌شود:
    - اگر در پاسخ درگاه `amount` موجود باشد، باید با مبلغ فاکتور محلی برابر باشد.
    - اگر در جزئیات فاکتور `order_id` تنظیم شده باشد و درگاه `orderId` برگرداند، باید مقدارها برابر باشند.
- پاسخ‌های «قبلا تایید شده» (`code=101` برای Zarinpal و `result=201` برای Zibal) همچنان معتبرند، اما مقدار `already_verified=true` در `rawData` رسید اضافه می‌شود.
- رویداد `PaymentVerified` فقط برای تایید موفقِ بار اول dispatch می‌شود و برای پاسخ‌های already-verified ارسال نمی‌شود.
- پاسخ‌های نامعتبر/غیر JSON درگاه به شکل امن مدیریت شده و به استثناهای تایپ‌شده (`PurchaseFailedException` / `InvalidPaymentException`) تبدیل می‌شوند.

### اثرات سازگاری

امضای متدهای عمومی تغییر نکرده و نیاز به تغییر config وجود ندارد.

اگر قبلا `pay()` را قبل از `purchase()` موفق صدا می‌زدید، یا برای پاسخ‌های نامعتبر درگاه به خطاهای سطح پایین متکی بودید، مدیریت خطا را برای گرفتن استثناهای تایپ‌شده به‌روز کنید.

اکنون `verify()` نیاز دارد مبلغ فاکتور مثبت و تنظیم‌شده باشد. اگر قبلا فقط با شناسه تراکنش verify می‌کردید، مبلغ اصلی فاکتور را نیز در جریان verify وارد کنید.

`PaymentVerified` دیگر برای پاسخ‌هایی که نشان‌دهنده «قبلا تایید شده» هستند (`code=101` / `result=201`) dispatch نمی‌شود. اگر listener شما به verify تکراری وابسته بوده، منطق idempotent خودتان را در لایه persistence مبنا قرار دهید.

برای `Zibal`: اگر `amount` یا `orderId` گزارش‌شده از درگاه با داده محلی ناسازگار باشد، verify ممکن است `InvalidPaymentException` پرتاب کند. این یک بهبود امنیتی است. API سمت شما تغییری نیاز ندارد، ولی باید این exception را مدیریت کنید.

## کدهای درگاه (ترجمه انگلیسی پیام‌ها)

این ترجمه‌ها تا حد ممکن توسط پکیج استفاده می‌شوند تا پیام استثناها قابل پیش‌بینی باشند.

### Zarinpal - کدهای رایج

| کد | معنی |
|---:|---|
| -9 | خطای اعتبارسنجی |
| -10 | ترمینال معتبر نیست (merchant_id یا IP را بررسی کنید) |
| -11 | ترمینال فعال نیست |
| -12 | تعداد تلاش بیش از حد |
| -13 | محدودیت ترمینال پر شده است |
| -14 | دامنه callback با دامنه ثبت‌شده ترمینال مطابقت ندارد |
| -15 | کاربر ترمینال تعلیق شده است |
| -16 | سطح کاربر ترمینال معتبر نیست |
| -17 | سطح کاربر ترمینال معتبر نیست |
| -18 | آدرس ارجاع‌دهنده با دامنه ثبت‌شده مطابقت ندارد |
| -19 | تراکنش‌های ترمینال مسدود شده است |
| 100 | موفق |
| 101 | قبلا تایید شده |

### Zarinpal - کدهای Purchase

| کد | معنی |
|---:|---|
| -30 | ترمینال اجازه دستمزد شناور ندارد |
| -31 | ترمینال اجازه دستمزد ندارد (حساب بانکی پیش‌فرض تنظیم نشده) |
| -32 | دستمزد نامعتبر است (جمع شناور بیش از حد مجاز است) |
| -33 | مقادیر دستمزد شناور نامعتبر است |
| -34 | دستمزد نامعتبر است (جمع ثابت بیش از حد مجاز است) |
| -35 | تعداد بخش‌های دستمزد شناور از سقف گذشته است |
| -36 | حداقل مبلغ دستمزد شناور 10,000 ریال است |
| -37 | یک یا چند IBAN دستمزد غیرفعال است |
| -38 | تنظیمات IBAN برای دستمزد نامعتبر است |
| -39 | خطای عمومی دستمزد |
| -40 | `expire_in` نامعتبر است |
| -41 | سقف مبلغ 100,000,000 تومان است |

### Zarinpal - کدهای Verify

| کد | معنی |
|---:|---|
| -50 | Session نامعتبر است (عدم تطابق مبلغ) |
| -51 | Session نامعتبر است (پرداخت موفق نبوده/Session غیرفعال است) |
| -52 | خطای سیستمی غیرمنتظره |
| -53 | Session متعلق به این merchant_id نیست |
| -54 | authority نامعتبر |
| -55 | درخواست پرداخت دستی یافت نشد |

### Zibal - کدهای نتیجه Request

| کد | معنی |
|---:|---|
| 100 | موفق |
| 102 | Merchant یافت نشد |
| 103 | Merchant غیرفعال است / قرارداد درگاه امضا نشده |
| 104 | Merchant نامعتبر |
| 105 | مبلغ باید بیش از 1,000 ریال باشد |
| 106 | callbackUrl نامعتبر است (باید با http/https شروع شود) |
| 107 | percentMode نامعتبر است (فقط 0 یا 1) |
| 108 | یک یا چند beneficiary در multiplexingInfos نامعتبر است |
| 109 | یک یا چند beneficiary در multiplexingInfos غیرفعال است |
| 110 | مقدار `id=self` در multiplexingInfos وجود ندارد |
| 111 | مبلغ با مجموع سهم‌ها در multiplexingInfos برابر نیست |
| 112 | موجودی کیف پول کارمزد کافی نیست |
| 113 | مبلغ از سقف تراکنش بیشتر است |
| 114 | کد ملی نامعتبر |
| 115 | IP در پنل ثبت نشده است |

### Zibal - کدهای نتیجه Verify

| کد | معنی |
|---:|---|
| 100 | موفق |
| 102 | Merchant یافت نشد |
| 103 | Merchant غیرفعال |
| 104 | Merchant نامعتبر |
| 201 | قبلا تایید شده |
| 202 | سفارش پرداخت نشده یا پرداخت ناموفق بوده است |
| 203 | trackId نامعتبر |

### Zibal - کدهای وضعیت پرداخت

| وضعیت | معنی |
|---:|---|
| -1 | در انتظار پرداخت |
| -2 | خطای داخلی |
| 1 | پرداخت و تایید شده |
| 2 | پرداخت شده و هنوز تایید نشده |
| 3 | لغو توسط کاربر |
| 4 | شماره کارت نامعتبر |
| 5 | موجودی ناکافی |
| 6 | رمز/PIN نامعتبر |
| 7 | تعداد درخواست بیش از حد |
| 8 | سقف تعداد پرداخت اینترنتی روزانه رد شده |
| 9 | سقف مبلغ پرداخت اینترنتی روزانه رد شده |
| 10 | صادرکننده کارت نامعتبر |
| 11 | خطای سوئیچ |
| 12 | کارت در دسترس نیست |
| 15 | بازپرداخت شده |
| 16 | بازپرداخت در حال انجام |
| 18 | برگشت خورده |
| 21 | Merchant نامعتبر |

## ساخت درایور سفارشی

### 1. ساخت کلاس درگاه

یک پوشه جدید در `src/Drivers/YourGateway/` بسازید و `GatewayInterface` را پیاده‌سازی کنید:

```php
<?php

declare(strict_types=1);

namespace LaraPardakht\Drivers\MyGateway;

use LaraPardakht\Contracts\GatewayInterface;
use LaraPardakht\Contracts\InvoiceInterface;
use LaraPardakht\Contracts\ReceiptInterface;
use LaraPardakht\DTOs\Receipt;
use LaraPardakht\DTOs\RedirectResponse;

class MyGatewayGateway implements GatewayInterface
{
    protected InvoiceInterface $invoice;

    public function __construct(
        protected readonly array $settings,
    ) {}

    public function setInvoice(InvoiceInterface $invoice): static
    {
        $this->invoice = $invoice;
        return $this;
    }

    public function purchase(): string
    {
        // اطلاعات فاکتور را به API درگاه ارسال کنید
        // شناسه تراکنش را برگردانید
    }

    public function pay(): RedirectResponse
    {
        // URL پرداخت درگاه را برگردانید
        return new RedirectResponse(url: 'https://gateway.com/pay/' . $this->invoice->getTransactionId());
    }

    public function verify(): ReceiptInterface
    {
        // پرداخت را تایید کنید
        // یک Receipt برگردانید
        return new Receipt(
            referenceId: 'ref-123',
            driver: 'mygateway',
            date: new \DateTimeImmutable(),
            rawData: [],
        );
    }
}
```

### 2. ثبت در config

درایور خود را به `config/larapardakht.php` اضافه کنید:

```php
'drivers' => [
    // ... existing drivers
    'mygateway' => [
        'api_key' => env('MYGATEWAY_API_KEY', ''),
        'sandbox' => env('MYGATEWAY_SANDBOX', false),
        'callback_url' => env('PAYMENT_CALLBACK_URL', ''),
    ],
],

'map' => [
    // ... existing mappings
    'mygateway' => \LaraPardakht\Drivers\MyGateway\MyGatewayGateway::class,
],
```

### 3. نوشتن تست

تست‌ها را در `tests/Drivers/MyGateway/` بنویسید و برای شبیه‌سازی API از `Http::fake()` استفاده کنید.

## رویدادها

| رویداد | زمان ارسال |
|-------|-----------|
| `PaymentPurchased` | بعد از خرید موفق (دریافت transaction ID) |
| `PaymentVerified` | بعد از اولین تایید موفق پرداخت (برای پاسخ‌های `already_verified` ارسال نمی‌شود) |

```php
use LaraPardakht\Events\PaymentPurchased;
use LaraPardakht\Events\PaymentVerified;

// In your EventServiceProvider or listener
Event::listen(PaymentPurchased::class, function ($event) {
    logger("Payment purchased: {$event->transactionId} via {$event->driver}");
});

Event::listen(PaymentVerified::class, function ($event) {
    logger("Payment verified: {$event->receipt->getReferenceId()} via {$event->driver}");
});
```

## تست

برای اجرای تست‌ها:

```bash
./vendor/bin/pest
```

## مجوز

مجوز MIT. برای جزئیات فایل [LICENSE](LICENSE) را ببینید.
