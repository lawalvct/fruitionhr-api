<?php

use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\PaymentService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Money safety: a charge must fulfil exactly once, and only for the amount we
 * actually asked for. A webhook and a client-triggered verify routinely arrive
 * together, so "already successful" has to short-circuit.
 */
function pendingPayment(Tenant $tenant, Plan $plan, int $amount = 1800000): Payment
{
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
        'reference' => 'PST_TEST_'.uniqid(),
        'amount' => $amount,
        'currency' => 'NGN',
        'status' => Payment::STATUS_PENDING,
        'employee_count' => 12,
    ])->save();

    return $payment->refresh();
}

test('a confirmed payment activates the subscription and rolls the period forward', function (): void {
    $tenant = Tenant::factory()->create();
    $plan = Plan::factory()->create(['billing_interval' => Plan::INTERVAL_MONTHLY]);
    $payment = pendingPayment($tenant, $plan);

    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response([
        'status' => true,
        'data' => ['status' => 'success', 'amount' => 1800000, 'currency' => 'NGN'],
    ])]);

    $verified = app(PaymentService::class)->verify($payment->reference);

    expect($verified->status)->toBe(Payment::STATUS_SUCCESSFUL)
        ->and($verified->paid_at)->not->toBeNull();

    $subscription = Subscription::withoutGlobalScopes()->find($payment->subscription_id);

    expect($subscription->status)->toBe(Subscription::STATUS_ACTIVE)
        ->and($subscription->current_period_end->isFuture())->toBeTrue()
        ->and($subscription->isUsable())->toBeTrue();
});

test('verifying twice fulfils only once', function (): void {
    $tenant = Tenant::factory()->create();
    $plan = Plan::factory()->create();
    $payment = pendingPayment($tenant, $plan);

    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response([
        'status' => true,
        'data' => ['status' => 'success', 'amount' => 1800000],
    ])]);

    $service = app(PaymentService::class);
    $service->verify($payment->reference);
    $periodAfterFirst = Subscription::withoutGlobalScopes()->find($payment->subscription_id)->current_period_end;

    // Second call — as a webhook would, racing the client.
    $service->verify($payment->reference);
    $periodAfterSecond = Subscription::withoutGlobalScopes()->find($payment->subscription_id)->current_period_end;

    // The period must not advance a second month for one payment.
    expect($periodAfterSecond->timestamp)->toBe($periodAfterFirst->timestamp);

    // And the gateway is not re-queried once the payment is settled.
    Http::assertSentCount(1);
});

test('an underpayment does not activate the subscription', function (): void {
    $tenant = Tenant::factory()->create();
    $plan = Plan::factory()->create();
    $payment = pendingPayment($tenant, $plan, amount: 1800000);

    // Gateway reports ₦100 against an ₦18,000 charge.
    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response([
        'status' => true,
        'data' => ['status' => 'success', 'amount' => 10000],
    ])]);

    $verified = app(PaymentService::class)->verify($payment->reference);

    expect($verified->status)->toBe(Payment::STATUS_FAILED)
        ->and($verified->gateway_response['amount_mismatch'] ?? null)->toBeTrue();

    expect(Subscription::withoutGlobalScopes()->find($payment->subscription_id)->status)
        ->toBe(Subscription::STATUS_PAST_DUE);
});

test('a failed charge leaves the subscription untouched', function (): void {
    $tenant = Tenant::factory()->create();
    $plan = Plan::factory()->create();
    $payment = pendingPayment($tenant, $plan);

    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response([
        'status' => true,
        'data' => ['status' => 'failed', 'amount' => 0],
    ])]);

    $verified = app(PaymentService::class)->verify($payment->reference);

    expect($verified->status)->toBe(Payment::STATUS_FAILED);
    expect(Subscription::withoutGlobalScopes()->find($payment->subscription_id)->status)
        ->toBe(Subscription::STATUS_PAST_DUE);
});

test('an unknown reference is rejected rather than trusted', function (): void {
    Http::fake();

    app(PaymentService::class)->verify('PST_MADE_UP_REFERENCE');
})->throws(ValidationException::class);

test('payment references are unique at the database level', function (): void {
    $tenant = Tenant::factory()->create();
    $plan = Plan::factory()->create();
    $payment = pendingPayment($tenant, $plan);

    $duplicate = new Payment;
    $duplicate->forceFill([
        'tenant_id' => $tenant->id,
        'gateway' => 'paystack',
        'reference' => $payment->reference, // same reference
        'amount' => 1000,
        'status' => Payment::STATUS_PENDING,
    ])->save();
})->throws(QueryException::class);
