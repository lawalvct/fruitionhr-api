# Nomba & Paystack Integration — How Ballie Does It

A porting guide extracted from the live Ballie codebase, aimed at reusing these
integrations in a **REST API** Laravel project.

---

## 0. Read this first: which files are actually live

| File                                    | Gateway  | Status                                      |
| --------------------------------------- | -------- | ------------------------------------------- |
| `app/Helpers/PaymentHelper.php`         | Nomba    | **LIVE** — this is the real Nomba client    |
| `app/Helpers/NombaPyamentHelper.php`    | Nomba    | **DEAD CODE** — never instantiated anywhere |
| `app/Helpers/PaystackPaymentHelper.php` | Paystack | **LIVE**                                    |

`NombaPyamentHelper` (note the typo — "Pyament") is not referenced by a single
caller. A repo-wide grep finds only its own class declaration. It also reads
_different_ settings slugs than the live class, so copying it into a new project
would produce a client that silently fails to find credentials:

```
NombaPyamentHelper (dead) -> nomba_account_id, nomba_client_id, nomba_private_key
PaymentHelper      (live) -> nombaAccountID,  nombaClientID,  nombaPrivatekey
```

**Port `PaymentHelper.php` for Nomba, not `NombaPyamentHelper.php`.**

That said, the dead file is not worthless — it is the only place that implements
three things the live class lacks. Salvage these, then discard the rest:

- **Refunds** — `POST /v1/transaction/refund`
- **Webhook signature verification** — HMAC-SHA256, header `X-Nomba-Signature`
- USD to NGN conversion (hardcoded rate of 1500 — replace with a real FX source)

---

## 1. Architecture: two credential layers

Both gateways support **platform keys** and **merchant-owned keys**, decided per
tenant. This is the single most reusable idea in the integration.

```
Request to pay
      |
      v
getPaymentProvider()  ->  'ballie' | 'nomba' | 'paystack'
      |
      +- 'ballie'   -> platform keys (money lands in OUR account, paid out later)
      +- 'nomba' / 'paystack'
                    -> tenant's own keys (money lands in THEIR account directly)
```

Implemented in `app/Models/Concerns/ManagesPaymentGatewayCredentials.php`, a
trait shared by two models:

- `Tenant` — for invoice payment links
- `EcommerceSetting` — for the storefront

Both models carry a `payment_gateway_settings` JSON column cast to array.

### Credential shape

```php
const CREDENTIAL_FIELDS = [
    'nomba' => [
        'public' => ['account_id', 'client_id'],
        'secret' => ['private_key'],
    ],
    'paystack' => [
        'public' => ['public_key'],
        'secret' => ['secret_key'],
    ],
];
```

Everything under `secret` is encrypted at rest with `Crypt` and never echoed back
to a client (`getMaskedGatewayCredentials()` returns masked values for UI).

### The resolver

```php
public function gatewayCredentialsFor(string $provider): ?array
{
    if ($this->getPaymentProvider() !== $provider) return null;
    if (! $this->hasGatewayCredentials($provider))  return null;

    return $this->getGatewayCredentials($provider);
}
```

**`null` is meaningful**: it means "fall through to platform credentials". Both
helper constructors accept `?array $credentials` and load platform settings when
given `null`. Call sites read cleanly:

```php
$paystack = new PaystackPaymentHelper(
    $tenant->gatewayCredentialsFor(Tenant::PROVIDER_PAYSTACK)
);
```

---

## 2. Paystack

Class: `app/Helpers/PaystackPaymentHelper.php`. Base URL `https://api.paystack.co`.

### Initialize

```
POST /transaction/initialize
Authorization: Bearer {secret_key}

{
  "email":        "buyer@example.com",
  "amount":       150000,            <-- KOBO. (int) round($naira * 100)
  "reference":    "PS_A1B2C3D4E5_1699999999",
  "callback_url": "https://app.test/payment/callback",
  "metadata":     { "order_id": 42 }
}
```

Returns `data.authorization_url`, `data.access_code`, `data.reference`.

Auto-generated reference format when you do not supply one:

```php
'PS_' . strtoupper(Str::random(10)) . '_' . time()
```

### Verify

```
GET /transaction/verify/{reference}     // reference is rawurlencode()'d
```

Success is `$result['status'] === true && strtolower($data['status']) === 'success'`.
The helper divides `data.amount` by 100 to return Naira.

### Other endpoints wired up

| Method                           | Endpoint                    | Notes                                |
| -------------------------------- | --------------------------- | ------------------------------------ |
| `listBanks($country)`            | `GET /bank?country=nigeria` | Used by the payout / bank-select UI  |
| `refund($ref, $amount, $reason)` | `POST /refund`              | Amount in kobo; `null` = full refund |

### Webhooks

```php
$computed = hash_hmac('sha512', $rawRequestBody, $this->secretKey);
return hash_equals($computed, $signature);   // header: X-Paystack-Signature
```

Note **sha512** — Paystack uses sha512, Nomba uses sha256. `processWebhook()`
normalises three events: `charge.success`, `charge.failed`, `refund.processed`.

---

## 3. Nomba

Class: `app/Helpers/PaymentHelper.php`. Base URL `https://api.nomba.com`.

### Step 1 — token (required before every other call)

```
POST /v1/auth/token/issue
AccountId: {account_id}

{ "grant_type": "client_credentials",
  "client_id": "...", "client_secret": "..." }
```

Returns `data.access_token`.

**The token is fetched fresh on every single operation.** There is no caching, so
each payment init and each verify costs two HTTP round trips. Fix this when
porting (see section 6.5).

### Step 2 — create checkout order

```
POST /v1/checkout/order
accountId: {account_id}          <-- lowercase 'a' here, capital 'A' on the token call
Authorization: Bearer {access_token}

{
  "order": {
    "orderReference": "SUB_9f1c...",
    "callbackUrl":    "https://app.test/payment/callback",
    "customerEmail":  "buyer@example.com",
    "amount":         1500,      <-- MAJOR UNITS (Naira), NOT kobo
    "currency":       "NGN"
  },
  "tokenizeCard": "true"         <-- string, not boolean
}
```

Returns `data.checkoutLink` and `data.orderReference`.

### Step 3 — verify

```
GET /v1/checkout/transaction?idType=ORDER_ID&id={orderReference}
```

Nomba's verify response is **inconsistent**, so the helper tries three shapes in
order — keep this defensive ladder when porting:

```php
if (isset($result['data']['success'])) {
    $ok = $result['data']['success'] === true;
} elseif (($result['data']['message'] ?? null) === 'PAYMENT SUCCESSFUL') {
    $ok = true;
} elseif (isset($result['data']['status'])) {
    $ok = strtolower($result['data']['status']) === 'successful';
} else {
    $ok = false;  // 'unknown'
}
```

Supported currencies are validated client-side against `['NGN', 'USD']`.

---

## 4. Side-by-side

|                      | Paystack                        | Nomba                                                   |
| -------------------- | ------------------------------- | ------------------------------------------------------- |
| Auth                 | Static secret key               | OAuth token, re-issued per call                         |
| **Amount unit**      | **Kobo** (x 100)                | **Naira** (major units)                                 |
| Init endpoint        | `/transaction/initialize`       | `/v1/checkout/order`                                    |
| Redirect field       | `data.authorization_url`        | `data.checkoutLink`                                     |
| Verify               | `GET /transaction/verify/{ref}` | `GET /v1/checkout/transaction?idType=ORDER_ID&id={ref}` |
| Success test         | `data.status === 'success'`     | 3-way fallback ladder                                   |
| Callback query param | `reference` + `trxref`          | `orderReference`                                        |
| Webhook HMAC         | sha512                          | sha256                                                  |
| Webhook header       | `X-Paystack-Signature`          | `X-Nomba-Signature`                                     |
| Refunds              | Implemented, live               | Only in the dead helper                                 |

**The amount-unit difference is the number one source of bugs when porting.**
Paystack takes kobo, Nomba takes Naira. Passing Naira to Paystack undercharges by
100x; passing kobo to Nomba overcharges by 100x.

---

## 5. End-to-end flows in Ballie

### A. Storefront checkout

`app/Http/Controllers/Storefront/CheckoutController.php`

```
create Order (status: pending)
   -> generate reference: 'ORD_PS_' . Str::random(8) . '_' . $order->id
   -> $order->update(['payment_gateway_reference' => $reference])
   -> initializeTransaction(...) / processPayment(...)
   -> redirect($authorizationUrl)
   - - - customer pays on gateway - - -
   -> gateway redirects to storefront.payment.callback
   -> verify server-side
   -> fulfil: post voucher, decrement stock, receipt voucher
```

### B. Public invoice links

`app/Http/Controllers/PublicPaymentCallbackController.php`

One callback URL serves both gateways. It disambiguates by **which query param is
present**, then looks up the stored reference from `voucher.meta_data.payment_links`:

```php
if (isset($paymentLinks['nomba']) && $request->has('orderReference')) { ... }

if (! $verified && isset($paymentLinks['paystack'])
    && ($request->has('reference') || $request->has('trxref'))) { ... }
```

Two rules encoded here that are easy to get wrong:

1. **Verify against the reference you stored, not the one in the query string.**
   The query param is attacker-controlled.

2. **Verify with the same credentials the link was created with.** Checking a
   tenant's own Nomba transaction against platform keys returns "not found",
   which would silently discard a real payment. This is why the callback calls
   `$tenant->gatewayCredentialsFor(...)` rather than constructing a bare helper.

### C. Idempotency

Before recording, Ballie checks for an existing voucher:

```php
$existing = Voucher::where('tenant_id', $tenant->id)
    ->where('reference_number', 'LIKE', "%{$paymentReference}%")
    ->first();
```

A `LIKE %...%` scan is not a real guard — it is unindexed and can false-positive
on substring collisions. **Replace it with a unique constraint in the new
project** (see section 6.6).

### D. Reconciliation safety net

`app/Console/Commands/ReconfirmPendingPayments.php`

A scheduled command re-verifies still-pending payments against the gateway. This
catches customers who paid but closed the browser before the callback fired.
Anything money-related needs this; callbacks are not reliable.

---

## 6. Porting to a REST API — what must change

The Ballie code is written for a session-based web app. Six things break or need
rethinking behind a stateless API.

### 6.1 Return the URL, do not redirect

```php
// Ballie (web)
return redirect($paymentResult['checkoutLink']);

// REST API
return response()->json([
    'payment_url' => $result['authorization_url'],
    'reference'   => $result['reference'],
], 201);
```

### 6.2 `callback_url` must NOT be your API endpoint

The gateway redirects a **browser**, not your API client. Point `callback_url` at
a web page or a mobile deep link (`myapp://payment/callback`), and let _that_ call
your API to confirm.

```
API:     POST /api/payments             -> { payment_url, reference }
App:     opens payment_url in a browser / webview
Gateway: redirects to myapp://payment/callback?reference=XXX
App:     POST /api/payments/XXX/verify
API:     verifies server-side, returns final status
```

### 6.3 Webhooks become the source of truth

With no browser to redirect, webhooks carry the load. Register both, verify the
signature on the **raw** body (not the parsed array), and return 200 fast:

```php
Route::post('/webhooks/paystack', function (Request $request) {
    $helper = new PaystackPaymentHelper();

    if (! $helper->verifyWebhookSignature(
        $request->getContent(),
        $request->header('X-Paystack-Signature')
    )) {
        abort(401);
    }

    ProcessPaystackWebhook::dispatch($request->json()->all());

    return response()->noContent();   // ack immediately, work in queue
})->withoutMiddleware([VerifyCsrfToken::class]);
```

Exclude webhook routes from CSRF and from auth middleware.

### 6.4 Move credentials out of the database

Ballie reads platform keys from a `settings` table on every call. For a fresh
project use config + env, and reserve the DB only for genuine per-merchant keys:

```php
// config/services.php
'paystack' => [
    'public_key' => env('PAYSTACK_PUBLIC_KEY'),
    'secret_key' => env('PAYSTACK_SECRET_KEY'),
],
'nomba' => [
    'account_id'     => env('NOMBA_ACCOUNT_ID'),
    'client_id'      => env('NOMBA_CLIENT_ID'),
    'private_key'    => env('NOMBA_PRIVATE_KEY'),
    'webhook_secret' => env('NOMBA_WEBHOOK_SECRET'),
],
```

Keep the `?array $credentials` constructor override — that part is good design.

### 6.5 Cache the Nomba token

```php
private function accessToken(): ?string
{
    return Cache::remember(
        'nomba.token.' . md5($this->accountId),
        now()->addMinutes(50),
        fn () => /* POST /v1/auth/token/issue */
    );
}
```

Nomba tokens are short-lived; cache just under the TTL and halve your API calls.

### 6.6 Real idempotency

```php
Schema::create('payments', function (Blueprint $table) {
    $table->id();
    $table->string('gateway');                       // nomba | paystack
    $table->string('reference')->unique();           // <-- the actual guard
    $table->decimal('amount', 15, 2);
    $table->string('status')->default('pending');    // pending|successful|failed
    $table->json('gateway_response')->nullable();
    $table->timestamp('verified_at')->nullable();
    $table->timestamps();
});
```

Then wrap fulfilment in a transaction with a row lock, so a webhook and a
client-triggered verify arriving together cannot both fulfil:

```php
DB::transaction(function () use ($reference) {
    $payment = Payment::where('reference', $reference)->lockForUpdate()->first();

    if ($payment->status === 'successful') {
        return;   // already done
    }

    // ... verify + fulfil + mark successful
});
```

### 6.7 Drop the `Auth::check()` fallback

`PaymentHelper::processPayment()` falls back to `Auth::user()->email` when no
email is passed. In an API, always pass the email explicitly — implicit session
state is exactly what you do not want in a stateless handler.

---

## 7. Suggested clean interface for the new project

Ballie's two helpers have divergent shapes (`status` vs `success`,
`checkoutLink` vs `authorization_url`). Normalise behind one contract:

```php
interface PaymentGateway
{
    public function isConfigured(): bool;

    /** @return array{success:bool, payment_url:?string, reference:?string, message:?string} */
    public function initialize(
        int|float $amount,
        string $email,
        string $callbackUrl,
        ?string $reference = null,
        array $metadata = []
    ): array;

    /** @return array{success:bool, status:string, amount:float, currency:string, raw:array} */
    public function verify(string $reference): array;

    public function verifyWebhookSignature(string $rawPayload, ?string $signature): bool;

    public function refund(string $reference, ?float $amount = null, ?string $reason = null): array;
}
```

Have each driver own its unit conversion internally — callers should always pass
**Naira**, and `PaystackGateway::initialize()` does the `* 100` itself. That single
decision eliminates the most common porting bug from section 4.

Resolve drivers through a small factory:

```php
class PaymentGatewayManager
{
    public function driver(string $provider, ?array $credentials = null): PaymentGateway
    {
        return match ($provider) {
            'paystack' => new PaystackGateway($credentials),
            'nomba'    => new NombaGateway($credentials),
            default    => throw new InvalidArgumentException("Unknown gateway [$provider]"),
        };
    }
}
```

---

## 8. Checklist

- [ ] Port `PaymentHelper.php` (Nomba) and `PaystackPaymentHelper.php` — **not** `NombaPyamentHelper.php`
- [ ] Salvage refund + webhook-signature code from the dead Nomba helper
- [ ] Normalise both drivers behind one interface; unit conversion lives in the driver
- [ ] Credentials in `config/services.php`; encrypt any per-merchant keys at rest
- [ ] Cache the Nomba access token
- [ ] `payments.reference` unique + `lockForUpdate()` on fulfilment
- [ ] Webhook routes: raw-body signature check, no CSRF, no auth, queue the work
- [ ] Callback URL points at a web page or deep link, never the API itself
- [ ] Never trust callback / webhook params — always re-verify server-side
- [ ] Scheduled reconciliation command for stuck pending payments
- [ ] Test with gateway test keys before going near live keys

---

## 9. Source map

| Concern                    | File                                                                 |
| -------------------------- | -------------------------------------------------------------------- |
| Nomba client (live)        | `app/Helpers/PaymentHelper.php`                                      |
| Nomba client (dead)        | `app/Helpers/NombaPyamentHelper.php`                                 |
| Paystack client            | `app/Helpers/PaystackPaymentHelper.php`                              |
| Credential layering        | `app/Models/Concerns/ManagesPaymentGatewayCredentials.php`           |
| Storefront checkout        | `app/Http/Controllers/Storefront/CheckoutController.php`             |
| Invoice payment callback   | `app/Http/Controllers/PublicPaymentCallbackController.php`           |
| Merchant key management UI | `app/Http/Controllers/Tenant/Ecommerce/PaymentGatewayController.php` |
| Subscription billing       | `app/Http/Controllers/Tenant/SubscriptionController.php`             |
| Reconciliation cron        | `app/Console/Commands/ReconfirmPendingPayments.php`                  |

sample of the settings
below are all test key

-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Aug 16, 2026 at 04:07 PM
-- Server version: 8.4.3
-- PHP Version: 8.3.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/_!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT _/;
/_!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS _/;
/_!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION _/;
/_!40101 SET NAMES utf8mb4 _/;

--
-- Database: `ballie_db`
--

---

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
`id` bigint UNSIGNED NOT NULL,
`name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
`value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
`slug` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
`created_at` timestamp NULL DEFAULT NULL,
`updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `name`, `value`, `slug`, `created_at`, `updated_at`) VALUES
(28, 'Nomba Client ID', 'bc35ae3f-7c8c-49e7-beb4-2eb782ca40ce', 'nomba_client_id', '2025-07-24 19:49:28', '2025-07-24 19:49:29'),
(29, 'Nomba Private Key', 'u8Lud4fuGXwA0Hoh6CwLqWxetZL+U5G+q9GqTLHKqQqvZq4CABt28yT5bdqj3rwUkQvxjfoA/jK9LLCALjL6dQ==', 'nomba_private_key', '2025-07-24 19:49:28', '2025-07-24 19:49:29'),
(30, 'Nomba Account ID', '37256ccb-8b9e-4711-8f12-ed4be0cdfc1b', 'nomba_account_id', '2025-07-24 19:49:28', '2025-07-24 19:49:29'),
(31, 'Nomba Payment Gateway Enabled', 'true', 'nomba_enabled', '2025-07-24 19:49:28', '2025-07-24 19:49:29'),
(32, 'Paystack Public Key', 'pk_test_6cf3a33189c1ed48015d75a29f8a0805f0cf9dd2', 'paystack_public_key', '2025-07-24 19:49:28', '2025-07-24 19:49:29'),
(33, 'Paystack Secret Key', 'sk_test_2238e369cfdbc08b694630835d0b8a4dfb05178e', 'paystack_secret_key', '2025-07-24 19:49:28', '2025-07-24 19:49:29'),
(34, 'Paystack Payment Gateway Enabled', 'true', 'paystack_enabled', '2025-07-24 19:49:28', '2025-07-24 19:49:29'),
(41, 'NombaAccountID', '37256ccb-8b9e-4711-8f12-ed4be0cdfc1b', 'nombaAccountID', '2025-09-12 22:44:35', '2025-09-12 22:44:41'),
(42, 'NombaClientID', 'bc35ae3f-7c8c-49e7-beb4-2eb782ca40ce', 'nombaClientID', '2025-09-12 22:44:35', '2025-09-12 22:44:50'),
(43, 'NombaPrivatekey', 'u8Lud4fuGXwA0Hoh6CwLqWxetZL+U5G+q9GqTLHKqQqvZq4CABt28yT5bdqj3rwUkQvxjfoA/jK9LLCALjL6dQ==', 'nombaPrivatekey', '2025-09-12 22:44:35', '2025-09-12 22:44:56'),
(44, 'Paystack Generate Recipient', 'https://api.paystack.co/transferrecipient', 'paystackGenerateRecipient', '2020-01-07 16:30:00', NULL),
(45, 'Paystack OTP Verification', 'https://api.paystack.co/transfer', 'paystackOtpVerify', '2020-01-07 16:30:00', NULL),
(46, 'Paystack Resend OTP', 'https://api.paystack.co/transfer/resend_otp', 'paystackResendOtp', '2020-01-07 16:30:00', NULL),
(47, 'Paystack Finalize Transfer', 'https://api.paystack.co/transfer/finalize_transfer', 'paystackFinalTransfer', '2020-01-07 16:30:00', NULL),
(48, 'Paystack Email', 'paytest@mailboxt.com', 'paystackemail', '2019-11-22 04:41:04', NULL),
(49, 'Paystack Charge', 'https://api.paystack.co/charge', 'paystackCharge', NULL, NULL),
(50, 'Paystack Verify transaction', 'https://api.paystack.co/transaction/verify', 'paystackVerifytransaction', NULL, NULL),
(51, 'Paystack Disable Subscription', 'https://api.paystack.co/subscription/disable', 'paystackDisableSubscription', NULL, NULL),
(52, 'Paystack Enable Subscription', 'https://api.paystack.co/subscription/enable', 'paystackEnableSubscription', NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
ADD PRIMARY KEY (`id`),
ADD UNIQUE KEY `settings_slug_unique` (`slug`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=55;
COMMIT;

/_!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT _/;
/_!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS _/;
/_!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION _/;
