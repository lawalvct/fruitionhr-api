<?php

use App\Models\User;
use App\Modules\Admin\Models\PlatformRole;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Authorization\PlatformAbilities;

/**
 * Revenue reporting across every tenant.
 *
 * Two failure modes matter here and neither announces itself: the tenant scope
 * fails closed, so a missing withoutGlobalScope() reports a healthy business as
 * earning nothing; and counting the wrong rows (trials, failed charges) reports
 * a struggling one as thriving. Both are tested explicitly.
 */

function payment(Tenant $tenant, int $amount, string $status, ?string $paidAt = null): Payment
{
    $payment = new Payment;
    $payment->forceFill([
        'tenant_id' => $tenant->id,
        'gateway' => 'paystack',
        'reference' => 'REF_'.$tenant->id.'_'.fake()->unique()->numerify('######'),
        'amount' => $amount,
        'status' => $status,
        'paid_at' => $status === Payment::STATUS_SUCCESSFUL ? ($paidAt ?? now()) : null,
    ])->save();

    return $payment;
}

function revenueOwner(): User
{
    return User::factory()->platformAdministrator()->create();
}

test('collected counts settled money only, across every company', function (): void {
    $alpha = Tenant::factory()->create(['name' => 'Alpha Foods Ltd']);
    $beta = Tenant::factory()->create(['name' => 'Beta Logistics Ltd']);

    payment($alpha, 1_800_000, Payment::STATUS_SUCCESSFUL);
    payment($beta, 900_000, Payment::STATUS_SUCCESSFUL);
    // Neither of these is money.
    payment($beta, 5_000_000, Payment::STATUS_FAILED);
    payment($beta, 4_000_000, Payment::STATUS_PENDING);

    $this->actingAs(revenueOwner());
    $data = $this->getJson('/api/admin/v1/revenue')->assertOk()->json('data');

    expect($data['collected']['all_time'])->toBe(2_700_000)
        ->and($data['collected']['this_month'])->toBe(2_700_000);
});

test('collected is split by month, and last month is kept separate', function (): void {
    $tenant = Tenant::factory()->create();

    payment($tenant, 1_000_000, Payment::STATUS_SUCCESSFUL, now()->toDateTimeString());
    payment($tenant, 250_000, Payment::STATUS_SUCCESSFUL, now()->subMonthNoOverflow()->startOfMonth()->addDay()->toDateTimeString());

    $this->actingAs(revenueOwner());
    $data = $this->getJson('/api/admin/v1/revenue')->assertOk()->json('data');

    expect($data['collected']['this_month'])->toBe(1_000_000)
        ->and($data['collected']['last_month'])->toBe(250_000)
        ->and($data['collected']['all_time'])->toBe(1_250_000);

    $trend = collect($data['monthly_trend']);
    expect($trend)->toHaveCount(12)
        ->and($trend->last()['amount'])->toBe(1_000_000)
        ->and($trend->last()['payments'])->toBe(1)
        ->and($trend->slice(-2, 1)->first()['amount'])->toBe(250_000);
});

test('recurring revenue counts active subscriptions and never trials', function (): void {
    $plan = Plan::factory()->create(['name' => 'Growth', 'billing_interval' => Plan::INTERVAL_MONTHLY]);

    Subscription::factory()->create([
        'tenant_id' => Tenant::factory()->create()->id, 'plan_id' => $plan->id,
        'status' => Subscription::STATUS_ACTIVE, 'amount' => 1_200_000, 'employee_count' => 12,
    ]);
    Subscription::factory()->create([
        'tenant_id' => Tenant::factory()->create()->id, 'plan_id' => $plan->id,
        'status' => Subscription::STATUS_ACTIVE, 'amount' => 800_000, 'employee_count' => 8,
    ]);
    // A trial is pipeline, not income. Counting it would flatter the forecast.
    Subscription::factory()->create([
        'tenant_id' => Tenant::factory()->create()->id, 'plan_id' => $plan->id,
        'status' => Subscription::STATUS_TRIALING, 'amount' => 5_000_000, 'employee_count' => 50,
    ]);

    $this->actingAs(revenueOwner());
    $data = $this->getJson('/api/admin/v1/revenue')->assertOk()->json('data');

    expect($data['recurring']['mrr'])->toBe(2_000_000)
        ->and($data['recurring']['arr'])->toBe(24_000_000)
        ->and($data['recurring']['paying_companies'])->toBe(2)
        ->and($data['recurring']['average_per_company'])->toBe(1_000_000)
        ->and($data['expected']['trial_pipeline'])->toBe(5_000_000);
});

test('a yearly plan is normalised into the monthly figure', function (): void {
    $yearly = Plan::factory()->create(['name' => 'Annual', 'billing_interval' => Plan::INTERVAL_YEARLY]);

    Subscription::factory()->create([
        'tenant_id' => Tenant::factory()->create()->id, 'plan_id' => $yearly->id,
        'status' => Subscription::STATUS_ACTIVE, 'amount' => 12_000_000, 'employee_count' => 10,
    ]);

    $this->actingAs(revenueOwner());
    $data = $this->getJson('/api/admin/v1/revenue')->assertOk()->json('data');

    // Otherwise one annual customer would read as twelve months of MRR.
    expect($data['recurring']['mrr'])->toBe(1_000_000)
        ->and($data['recurring']['arr'])->toBe(12_000_000);
});

test('expected income counts renewals actually falling due', function (): void {
    $plan = Plan::factory()->create();

    Subscription::factory()->create([
        'tenant_id' => Tenant::factory()->create()->id, 'plan_id' => $plan->id,
        'status' => Subscription::STATUS_ACTIVE, 'amount' => 600_000,
        'current_period_end' => now()->addDays(10),
    ]);
    Subscription::factory()->create([
        'tenant_id' => Tenant::factory()->create()->id, 'plan_id' => $plan->id,
        'status' => Subscription::STATUS_ACTIVE, 'amount' => 400_000,
        'current_period_end' => now()->addDays(60),
    ]);
    // Past due is contracted but not arriving — reported separately so it can
    // be chased, never folded into what is expected.
    Subscription::factory()->create([
        'tenant_id' => Tenant::factory()->create()->id, 'plan_id' => $plan->id,
        'status' => Subscription::STATUS_PAST_DUE, 'amount' => 300_000,
        'current_period_end' => now()->addDays(5),
    ]);

    $this->actingAs(revenueOwner());
    $expected = $this->getJson('/api/admin/v1/revenue')->assertOk()->json('data.expected');

    expect($expected['next_30_days'])->toBe(600_000)
        ->and($expected['next_90_days'])->toBe(1_000_000)
        ->and($expected['at_risk'])->toBe(300_000)
        ->and($expected['at_risk_companies'])->toBe(1);
});

test('revenue is broken down by company, biggest payer first', function (): void {
    $small = Tenant::factory()->create(['name' => 'Small Ltd']);
    $big = Tenant::factory()->create(['name' => 'Big Ltd']);
    $plan = Plan::factory()->create(['name' => 'Growth']);

    payment($small, 500_000, Payment::STATUS_SUCCESSFUL);
    payment($big, 3_000_000, Payment::STATUS_SUCCESSFUL);
    payment($big, 1_000_000, Payment::STATUS_SUCCESSFUL);
    payment($big, 9_000_000, Payment::STATUS_FAILED);

    Subscription::factory()->create([
        'tenant_id' => $big->id, 'plan_id' => $plan->id,
        'status' => Subscription::STATUS_ACTIVE, 'amount' => 2_000_000, 'employee_count' => 20,
    ]);

    $this->actingAs(revenueOwner());
    $rows = $this->getJson('/api/admin/v1/revenue/companies')->assertOk()->json('data');

    expect($rows[0]['name'])->toBe('Big Ltd')
        ->and($rows[0]['collected'])->toBe(4_000_000)
        ->and($rows[0]['payments_count'])->toBe(2)
        ->and($rows[0]['subscription']['plan'])->toBe('Growth')
        ->and($rows[0]['subscription']['is_earning'])->toBeTrue()
        ->and($rows[1]['name'])->toBe('Small Ltd')
        ->and($rows[1]['collected'])->toBe(500_000)
        ->and($rows[1]['subscription'])->toBeNull();
});

test('revenue by plan shows where the money comes from', function (): void {
    $growth = Plan::factory()->create(['name' => 'Growth']);
    $starter = Plan::factory()->create(['name' => 'Starter']);

    foreach ([[$growth, 1_500_000], [$growth, 500_000], [$starter, 300_000]] as [$plan, $amount]) {
        Subscription::factory()->create([
            'tenant_id' => Tenant::factory()->create()->id, 'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE, 'amount' => $amount, 'employee_count' => 5,
        ]);
    }

    $this->actingAs(revenueOwner());
    $byPlan = $this->getJson('/api/admin/v1/revenue')->assertOk()->json('data.by_plan');

    expect($byPlan[0]['plan'])->toBe('Growth')
        ->and($byPlan[0]['mrr'])->toBe(2_000_000)
        ->and($byPlan[0]['companies'])->toBe(2)
        ->and($byPlan[1]['plan'])->toBe('Starter')
        ->and($byPlan[1]['mrr'])->toBe(300_000);
});

test('an empty platform reports zero rather than failing', function (): void {
    $this->actingAs(revenueOwner());
    $data = $this->getJson('/api/admin/v1/revenue')->assertOk()->json('data');

    expect($data['collected']['all_time'])->toBe(0)
        ->and($data['recurring']['mrr'])->toBe(0)
        // Division by zero when nobody is paying yet.
        ->and($data['recurring']['average_per_company'])->toBe(0)
        ->and($data['monthly_trend'])->toHaveCount(12)
        ->and($data['by_plan'])->toBe([]);
});

test('revenue is its own ability, separate from billing', function (): void {
    $billingOnly = PlatformRole::factory()->granting([PlatformAbilities::BILLING])->create();
    $this->actingAs(User::factory()->platformStaff($billingOnly)->create());

    // Running the price list is a different job from seeing what the business
    // earns, so billing alone does not open the books.
    $this->getJson('/api/admin/v1/billing/plans')->assertOk();
    $this->getJson('/api/admin/v1/revenue')->assertForbidden();
    $this->getJson('/api/admin/v1/revenue/companies')->assertForbidden();

    $revenueOnly = PlatformRole::factory()->granting([PlatformAbilities::REVENUE])->create();
    $this->actingAs(User::factory()->platformStaff($revenueOnly)->create());

    $this->getJson('/api/admin/v1/revenue')->assertOk();
    $this->getJson('/api/admin/v1/billing/plans')->assertForbidden();
});

test('the subscriptions list no longer discloses revenue', function (): void {
    $tenant = Tenant::factory()->create();
    payment($tenant, 7_500_000, Payment::STATUS_SUCCESSFUL);

    $billingOnly = PlatformRole::factory()->granting([PlatformAbilities::BILLING])->create();
    $this->actingAs(User::factory()->platformStaff($billingOnly)->create());

    // Regression guard: the summary used to carry lifetime revenue, which
    // would hand it to everyone with billing and make the split cosmetic.
    $body = $this->getJson('/api/admin/v1/billing/subscriptions')->assertOk()->json();

    expect(json_encode($body))->not->toContain('7500000');
});
