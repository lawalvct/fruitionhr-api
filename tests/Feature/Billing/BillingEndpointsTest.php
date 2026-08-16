<?php

use App\Models\User;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Employee\Models\Employee;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Http;

/**
 * A tenant may only ever see and change its own billing. These pin the gate as
 * much as the happy path.
 */
function billingTenant(int $employees = 12): array
{
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    Employee::factory()->count($employees)->create(['employment_status' => Employee::STATUS_ACTIVE]);

    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'email_verified_at' => now(),
    ]);

    return [$tenant, $owner];
}

test('the plan list is quoted against this tenant headcount', function (): void {
    [$tenant, $owner] = billingTenant(employees: 12);
    Plan::factory()->create(['name' => 'Growth', 'price_per_employee' => 150000, 'min_employees' => 1]);

    $this->actingAs($owner);

    $response = $this->getJson('/api/v1/billing/plans')->assertOk();

    expect($response->json('meta.employees'))->toBe(12);
    expect($response->json('data.0.quote.amount'))->toBe(1800000); // 12 x ₦1,500
});

test('a tenant can subscribe and lands on a trial', function (): void {
    [$tenant, $owner] = billingTenant();
    $plan = Plan::factory()->create(['trial_days' => 14]);

    $this->actingAs($owner);

    $this->postJson('/api/v1/billing/subscribe', ['plan_id' => $plan->id])
        ->assertOk()
        ->assertJsonPath('data.status', Subscription::STATUS_TRIALING)
        ->assertJsonPath('data.on_trial', true);
});

test('the subscription endpoint quotes what the next renewal will cost', function (): void {
    [$tenant, $owner] = billingTenant(employees: 10);
    $plan = Plan::factory()->create(['price_per_employee' => 100000, 'min_employees' => 1]);

    $this->actingAs($owner);
    $this->postJson('/api/v1/billing/subscribe', ['plan_id' => $plan->id])->assertOk();

    // Two more people join mid-period.
    app(CurrentTenant::class)->set($tenant);
    Employee::factory()->count(2)->create(['employment_status' => Employee::STATUS_ACTIVE]);

    $response = $this->getJson('/api/v1/billing/subscription')->assertOk();

    expect($response->json('meta.employees'))->toBe(12)
        // Renewal reflects the new headcount, not the amount last charged.
        ->and($response->json('meta.renewal_quote.amount'))->toBe(1200000);
});

test('starting a payment returns a gateway url and stores a pending payment', function (): void {
    [$tenant, $owner] = billingTenant(employees: 12);
    $plan = Plan::factory()->create(['price_per_employee' => 150000, 'min_employees' => 1]);

    Http::fake(['api.paystack.co/transaction/initialize' => Http::response([
        'status' => true,
        'data' => ['authorization_url' => 'https://checkout.paystack.com/go', 'reference' => 'ignored'],
    ])]);

    $this->actingAs($owner);
    $this->postJson('/api/v1/billing/subscribe', ['plan_id' => $plan->id])->assertOk();

    $response = $this->postJson('/api/v1/billing/payments', ['gateway' => 'paystack'])
        ->assertCreated()
        ->assertJsonPath('data.payment_url', 'https://checkout.paystack.com/go')
        ->assertJsonPath('data.amount', 1800000);

    $this->assertDatabaseHas('payments', [
        'reference' => $response->json('data.reference'),
        'tenant_id' => $tenant->id,
        'status' => Payment::STATUS_PENDING,
        'amount' => 1800000,
    ]);
});

test('verifying activates the subscription', function (): void {
    [$tenant, $owner] = billingTenant(employees: 10);
    $plan = Plan::factory()->create(['price_per_employee' => 100000, 'min_employees' => 1]);

    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => ['authorization_url' => 'https://checkout.paystack.com/go'],
        ]),
        'api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => ['status' => 'success', 'amount' => 1000000],
        ]),
    ]);

    $this->actingAs($owner);
    $this->postJson('/api/v1/billing/subscribe', ['plan_id' => $plan->id])->assertOk();
    $reference = $this->postJson('/api/v1/billing/payments')->json('data.reference');

    $this->postJson("/api/v1/billing/payments/{$reference}/verify")
        ->assertOk()
        ->assertJsonPath('data.status', Payment::STATUS_SUCCESSFUL);

    $this->getJson('/api/v1/billing/subscription')
        ->assertOk()
        ->assertJsonPath('data.status', Subscription::STATUS_ACTIVE)
        ->assertJsonPath('data.is_usable', true);
});

test('a tenant cannot verify another tenant payment', function (): void {
    [$alpha, $alphaOwner] = billingTenant();
    [$beta] = billingTenant();

    // A payment belonging to beta.
    $betaPayment = new Payment;
    $betaPayment->forceFill([
        'tenant_id' => $beta->id,
        'gateway' => 'paystack',
        'reference' => 'PST_BETA_ONLY',
        'amount' => 500000,
        'status' => Payment::STATUS_PENDING,
    ])->save();

    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response([
        'status' => true,
        'data' => ['status' => 'success', 'amount' => 500000],
    ])]);

    $this->actingAs($alphaOwner);

    $this->postJson('/api/v1/billing/payments/PST_BETA_ONLY/verify')->assertNotFound();
});

test('the payment history shows only this tenant payments', function (): void {
    [$alpha, $alphaOwner] = billingTenant();
    [$beta] = billingTenant();

    foreach ([[$alpha, 'PST_A'], [$beta, 'PST_B']] as [$tenant, $ref]) {
        $payment = new Payment;
        $payment->forceFill([
            'tenant_id' => $tenant->id,
            'gateway' => 'paystack',
            'reference' => $ref,
            'amount' => 100000,
            'status' => Payment::STATUS_SUCCESSFUL,
        ])->save();
    }

    $this->actingAs($alphaOwner);
    app(CurrentTenant::class)->set($alpha);

    $references = collect($this->getJson('/api/v1/billing/payments')->assertOk()->json('data'))
        ->pluck('reference');

    expect($references)->toContain('PST_A')->not->toContain('PST_B');
});

test('cancelling keeps access to the end of the paid period', function (): void {
    [$tenant, $owner] = billingTenant();
    $plan = Plan::factory()->create();
    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => Subscription::STATUS_ACTIVE,
        'current_period_end' => now()->addDays(15),
    ]);

    $this->actingAs($owner);

    $this->postJson('/api/v1/billing/subscription/cancel')
        ->assertOk()
        ->assertJsonPath('data.status', Subscription::STATUS_CANCELLED)
        ->assertJsonPath('data.is_usable', true);
});

test('billing stays reachable for a tenant whose email is not verified', function (): void {
    // Otherwise a company that cannot use the product also cannot pay to fix it.
    [$tenant, $owner] = billingTenant();
    $owner->forceFill(['email_verified_at' => null])->save();
    Plan::factory()->create();

    $this->actingAs($owner);

    $this->getJson('/api/v1/billing/plans')->assertOk();
    // A normal module route is still gated.
    $this->getJson('/api/v1/branches')->assertForbidden();
});

test('billing is closed to guests', function (): void {
    $this->getJson('/api/v1/billing/plans')->assertUnauthorized();
    $this->postJson('/api/v1/billing/subscribe', ['plan_id' => 1])->assertUnauthorized();
});
