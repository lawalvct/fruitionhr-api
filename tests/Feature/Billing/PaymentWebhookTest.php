<?php

use App\Modules\Billing\Jobs\ProcessPaymentWebhook;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\PaymentService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Webhooks are unauthenticated, so the HMAC signature is the whole gate. A
 * forged "payment successful" must never reach the fulfilment path.
 */
beforeEach(function (): void {
    config()->set('services.paystack.secret_key', 'sk_test_dummy');
    config()->set('services.nomba.webhook_secret', 'whsec');
});

function paystackWebhook(string $reference): string
{
    return json_encode(['event' => 'charge.success', 'data' => ['reference' => $reference]]);
}

test('a correctly signed paystack webhook is accepted and queued', function (): void {
    Queue::fake();
    $body = paystackWebhook('PST_ABC');

    $this->call('POST', '/api/v1/webhooks/paystack', [], [], [], [
        'HTTP_X-Paystack-Signature' => hash_hmac('sha512', $body, 'sk_test_dummy'),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertNoContent();

    Queue::assertPushed(ProcessPaymentWebhook::class, fn ($job) => $job->reference === 'PST_ABC');
});

test('an unsigned or forged webhook is rejected and never queued', function (): void {
    Queue::fake();
    $body = paystackWebhook('PST_FORGED');

    // No signature at all.
    $this->call('POST', '/api/v1/webhooks/paystack', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertStatus(401);

    // A signature made with the wrong secret.
    $this->call('POST', '/api/v1/webhooks/paystack', [], [], [], [
        'HTTP_X-Paystack-Signature' => hash_hmac('sha512', $body, 'attacker-guess'),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertStatus(401);

    Queue::assertNothingPushed();
});

test('a webhook signed for the wrong body is rejected', function (): void {
    Queue::fake();

    // Signature computed over a different payload than the one sent.
    $signature = hash_hmac('sha512', paystackWebhook('PST_ONE'), 'sk_test_dummy');

    $this->call('POST', '/api/v1/webhooks/paystack', [], [], [], [
        'HTTP_X-Paystack-Signature' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], paystackWebhook('PST_TWO'))->assertStatus(401);

    Queue::assertNothingPushed();
});

test('nomba webhooks are verified with sha256 and their own reference shape', function (): void {
    Queue::fake();
    $body = json_encode(['data' => ['order' => ['orderReference' => 'NMB_XYZ']]]);

    $this->call('POST', '/api/v1/webhooks/nomba', [], [], [], [
        'HTTP_X-Nomba-Signature' => hash_hmac('sha256', $body, 'whsec'),
        'CONTENT_TYPE' => 'application/json',
    ], $body)->assertNoContent();

    Queue::assertPushed(ProcessPaymentWebhook::class, fn ($job) => $job->reference === 'NMB_XYZ');
});

test('an unknown gateway is not routed anywhere', function (): void {
    Queue::fake();

    $this->postJson('/api/v1/webhooks/stripe', [])->assertNotFound();

    Queue::assertNothingPushed();
});

test('the webhook job re-reads the amount from the gateway, not the payload', function (): void {
    $tenant = Tenant::factory()->create();
    $plan = Plan::factory()->create();
    $subscription = Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => Subscription::STATUS_PAST_DUE,
        'current_period_end' => now()->subDay(),
    ]);

    $payment = new Payment;
    $payment->forceFill([
        'tenant_id' => $tenant->id,
        'subscription_id' => $subscription->id,
        'gateway' => 'paystack',
        'reference' => 'PST_REAL',
        'amount' => 1800000,
        'status' => Payment::STATUS_PENDING,
    ])->save();

    // The gateway is the source of truth and says the charge failed, even
    // though a webhook claimed "charge.success".
    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response([
        'status' => true,
        'data' => ['status' => 'failed', 'amount' => 0],
    ])]);

    (new ProcessPaymentWebhook('PST_REAL'))->handle(app(PaymentService::class));

    expect($payment->fresh()->status)->toBe(Payment::STATUS_FAILED);
    expect(Subscription::withoutGlobalScopes()->find($subscription->id)->status)
        ->toBe(Subscription::STATUS_PAST_DUE);
});

test('a webhook for an unknown reference is swallowed rather than retried forever', function (): void {
    Http::fake();

    // Must not throw — an unrecognised reference is not our payment.
    (new ProcessPaymentWebhook('PST_NOT_OURS'))->handle(app(PaymentService::class));
})->throwsNoExceptions();

test('reconciliation confirms payments whose callback never arrived', function (): void {
    $tenant = Tenant::factory()->create();
    $plan = Plan::factory()->create();
    $subscription = Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => Subscription::STATUS_PAST_DUE,
        'current_period_end' => now()->subDay(),
    ]);

    $payment = new Payment;
    $payment->forceFill([
        'tenant_id' => $tenant->id,
        'subscription_id' => $subscription->id,
        'gateway' => 'paystack',
        'reference' => 'PST_STRANDED',
        'amount' => 1000000,
        'status' => Payment::STATUS_PENDING,
        'created_at' => now()->subMinutes(30), // customer closed the browser
    ])->save();

    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response([
        'status' => true,
        'data' => ['status' => 'success', 'amount' => 1000000],
    ])]);

    $this->artisan('billing:reconcile')->assertSuccessful();

    expect($payment->fresh()->status)->toBe(Payment::STATUS_SUCCESSFUL);
    expect(Subscription::withoutGlobalScopes()->find($subscription->id)->status)
        ->toBe(Subscription::STATUS_ACTIVE);
});

test('reconciliation leaves very recent payments alone', function (): void {
    $tenant = Tenant::factory()->create();
    $payment = new Payment;
    $payment->forceFill([
        'tenant_id' => $tenant->id,
        'gateway' => 'paystack',
        'reference' => 'PST_FRESH',
        'amount' => 1000,
        'status' => Payment::STATUS_PENDING,
        'created_at' => now(), // still on the gateway page
    ])->save();

    Http::fake();

    $this->artisan('billing:reconcile')->assertSuccessful();

    expect($payment->fresh()->status)->toBe(Payment::STATUS_PENDING);
    Http::assertNothingSent();
});

test('long-stale payments are abandoned rather than checked forever', function (): void {
    $tenant = Tenant::factory()->create();
    $payment = new Payment;
    $payment->forceFill([
        'tenant_id' => $tenant->id,
        'gateway' => 'paystack',
        'reference' => 'PST_OLD',
        'amount' => 1000,
        'status' => Payment::STATUS_PENDING,
        'created_at' => now()->subDays(3),
    ])->save();

    Http::fake();

    $this->artisan('billing:reconcile')->assertSuccessful();

    expect($payment->fresh()->status)->toBe(Payment::STATUS_ABANDONED);
    Http::assertNothingSent();
});
