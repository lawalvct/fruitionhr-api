<?php

use App\Modules\Billing\Gateways\NombaGateway;
use App\Modules\Billing\Gateways\PaymentGatewayManager;
use App\Modules\Billing\Gateways\PaystackGateway;
use Illuminate\Support\Facades\Http;

/**
 * The single most expensive bug available here is the amount unit: Paystack
 * takes kobo, Nomba takes Naira. Getting it backwards over- or under-charges
 * by 100x, so both directions are pinned.
 */
beforeEach(function (): void {
    config()->set('services.paystack.secret_key', 'sk_test_dummy');
    config()->set('services.nomba.account_id', 'acct-1');
    config()->set('services.nomba.client_id', 'client-1');
    config()->set('services.nomba.private_key', 'secret-1');
});

test('paystack is sent kobo unchanged', function (): void {
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/abc', 'reference' => 'PST_X'],
        ]),
    ]);

    $result = (new PaystackGateway)->initialize(
        amountKobo: 1800000, // ₦18,000
        email: 'owner@tenant.test',
        callbackUrl: 'https://app.test/billing/callback',
        reference: 'PST_X',
    );

    expect($result->success)->toBeTrue()
        ->and($result->paymentUrl)->toBe('https://checkout.paystack.com/abc');

    Http::assertSent(fn ($request) => $request['amount'] === 1800000);
});

test('nomba is sent naira, converted from kobo', function (): void {
    Http::fake([
        'api.nomba.com/v1/auth/token/issue' => Http::response(['data' => ['access_token' => 'tok']]),
        'api.nomba.com/v1/checkout/order' => Http::response([
            'data' => ['checkoutLink' => 'https://checkout.nomba.com/xyz', 'orderReference' => 'NMB_X'],
        ]),
    ]);

    $result = (new NombaGateway)->initialize(
        amountKobo: 1800000, // ₦18,000
        email: 'owner@tenant.test',
        callbackUrl: 'https://app.test/billing/callback',
        reference: 'NMB_X',
    );

    expect($result->success)->toBeTrue()
        ->and($result->paymentUrl)->toBe('https://checkout.nomba.com/xyz');

    // 1_800_000 kobo must arrive as 18000 Naira, not 1_800_000.
    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/checkout/order')
            && $request['order']['amount'] === 18000.0;
    });
});

test('paystack verify reports kobo and nomba verify converts naira back to kobo', function (): void {
    Http::fake([
        'api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => ['status' => 'success', 'amount' => 1800000, 'currency' => 'NGN'],
        ]),
        'api.nomba.com/v1/auth/token/issue' => Http::response(['data' => ['access_token' => 'tok']]),
        'api.nomba.com/v1/checkout/transaction*' => Http::response([
            'data' => ['success' => true, 'amount' => 18000, 'currency' => 'NGN'],
        ]),
    ]);

    expect((new PaystackGateway)->verify('PST_X')->amount)->toBe(1800000);
    expect((new NombaGateway)->verify('NMB_X')->amount)->toBe(1800000);
});

test('nomba success is read through its three response shapes', function (array $data, bool $expected): void {
    Http::fake([
        'api.nomba.com/v1/auth/token/issue' => Http::response(['data' => ['access_token' => 'tok']]),
        'api.nomba.com/v1/checkout/transaction*' => Http::response(['data' => $data]),
    ]);

    expect((new NombaGateway)->verify('NMB_X')->success)->toBe($expected);
})->with([
    'success flag' => [['success' => true], true],
    'success flag false' => [['success' => false], false],
    'message wording' => [[['message' => 'PAYMENT SUCCESSFUL']][0], true],
    'status string' => [['status' => 'SUCCESSFUL'], true],
    'status failed' => [['status' => 'failed'], false],
    'nothing recognisable' => [['foo' => 'bar'], false],
]);

test('a declined paystack response does not read as success', function (): void {
    Http::fake([
        'api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => ['status' => 'failed', 'amount' => 0],
        ]),
    ]);

    $result = (new PaystackGateway)->verify('PST_X');

    expect($result->success)->toBeFalse()->and($result->status)->toBe('failed');
});

test('webhook signatures use the algorithm each gateway actually uses', function (): void {
    $payload = '{"event":"charge.success"}';

    // Paystack: sha512.
    $paystack = new PaystackGateway;
    expect($paystack->verifyWebhookSignature($payload, hash_hmac('sha512', $payload, 'sk_test_dummy')))->toBeTrue();
    // The other gateway's algorithm must not pass.
    expect($paystack->verifyWebhookSignature($payload, hash_hmac('sha256', $payload, 'sk_test_dummy')))->toBeFalse();

    // Nomba: sha256.
    config()->set('services.nomba.webhook_secret', 'whsec');
    $nomba = new NombaGateway;
    expect($nomba->verifyWebhookSignature($payload, hash_hmac('sha256', $payload, 'whsec')))->toBeTrue();
    expect($nomba->verifyWebhookSignature($payload, hash_hmac('sha512', $payload, 'whsec')))->toBeFalse();
});

test('a missing or tampered signature is rejected', function (): void {
    $gateway = new PaystackGateway;

    expect($gateway->verifyWebhookSignature('{}', null))->toBeFalse()
        ->and($gateway->verifyWebhookSignature('{}', 'not-a-signature'))->toBeFalse();
});

test('the nomba token is cached rather than re-issued per call', function (): void {
    Http::fake([
        'api.nomba.com/v1/auth/token/issue' => Http::response(['data' => ['access_token' => 'tok']]),
        'api.nomba.com/v1/checkout/transaction*' => Http::response(['data' => ['success' => true, 'amount' => 1]]),
    ]);

    $gateway = new NombaGateway;
    $gateway->verify('A');
    $gateway->verify('B');

    // Two verifies, but only one token issue.
    Http::assertSentCount(3);
});

test('an unconfigured gateway fails closed instead of calling out', function (): void {
    Http::fake();
    config()->set('services.paystack.secret_key', null);

    $result = (new PaystackGateway)->initialize(1000, 'a@b.test', 'https://app.test/cb', 'REF');

    expect($result->success)->toBeFalse();
    Http::assertNothingSent();
});

test('the manager resolves drivers and reports which are configured', function (): void {
    $manager = new PaymentGatewayManager;

    expect($manager->driver('paystack'))->toBeInstanceOf(PaystackGateway::class)
        ->and($manager->driver('nomba'))->toBeInstanceOf(NombaGateway::class)
        ->and($manager->available())->toContain('paystack')->toContain('nomba');

    expect(fn () => $manager->driver('stripe'))->toThrow(InvalidArgumentException::class);
});
