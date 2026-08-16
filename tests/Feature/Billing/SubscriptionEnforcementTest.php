<?php

use App\Models\User;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Employee\Models\Employee;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Notification;

/**
 * A lapsed tenant drops to read-only: their own records stay visible and
 * exportable, but nothing can be changed until they pay. Payroll data belongs
 * to the customer, so locking them out of it is never acceptable.
 */
/** A tenant whose owner actually holds the roles the module routes require. */
function billingOwnerFor(Tenant $tenant): User
{
    app(TenantRoleProvisioner::class)->provision($tenant);
    setPermissionsTeamId($tenant->id);

    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => User::STATUS_ACTIVE,
        'email_verified_at' => now(),
    ]);
    $owner->assignRole('owner');

    return $owner;
}

function subscribedTenant(string $status, int $employees = 3): array
{
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    Employee::factory()->count($employees)->create(['employment_status' => Employee::STATUS_ACTIVE]);

    $plan = Plan::factory()->create();
    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => $status,
        'current_period_end' => $status === Subscription::STATUS_ACTIVE
            ? now()->addDays(20)
            : now()->subDay(),
        'trial_ends_at' => null,
        'ends_at' => null,
    ]);

    return [$tenant, billingOwnerFor($tenant)];
}

test('an active subscription can read and write as normal', function (): void {
    [, $owner] = subscribedTenant(Subscription::STATUS_ACTIVE);
    $this->actingAs($owner);

    $this->getJson('/api/v1/branches')->assertOk();
    $this->postJson('/api/v1/branches', ['name' => 'Head Office'])->assertCreated();
});

test('a lapsed subscription can still read its own data', function (): void {
    [, $owner] = subscribedTenant(Subscription::STATUS_EXPIRED);
    $this->actingAs($owner);

    // Reading and exporting must keep working — the records are theirs.
    $this->getJson('/api/v1/branches')->assertOk();
    $this->getJson('/api/v1/employees')->assertOk();
});

test('a lapsed subscription cannot write, and is told why', function (): void {
    [, $owner] = subscribedTenant(Subscription::STATUS_EXPIRED);
    $this->actingAs($owner);

    $response = $this->postJson('/api/v1/branches', ['name' => 'Blocked Branch'])
        // 402 rather than 403: this is payment, not permission.
        ->assertStatus(402);

    expect($response->json('message'))->toContain('subscription is not active');
});

test('billing stays reachable while lapsed so they can fix it', function (): void {
    [, $owner] = subscribedTenant(Subscription::STATUS_EXPIRED);
    $this->actingAs($owner);

    $this->getJson('/api/v1/billing/subscription')->assertOk();
    $this->getJson('/api/v1/billing/plans')->assertOk();
    // And starting a payment is a write that must NOT be blocked.
    $this->postJson('/api/v1/billing/subscribe', ['plan_id' => Plan::query()->value('id')])
        ->assertOk();
});

test('a trialing tenant is not treated as lapsed', function (): void {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    $plan = Plan::factory()->create();
    Subscription::factory()->trialing()->create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id]);
    $owner = billingOwnerFor($tenant);

    $this->actingAs($owner);
    $this->postJson('/api/v1/branches', ['name' => 'Trial Branch'])->assertCreated();
});

test('a cancelled tenant keeps writing until the paid period runs out', function (): void {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    $plan = Plan::factory()->create();
    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => Subscription::STATUS_CANCELLED,
        'ends_at' => now()->addDays(10), // still inside the paid window
    ]);
    $owner = billingOwnerFor($tenant);

    $this->actingAs($owner);
    $this->postJson('/api/v1/branches', ['name' => 'Still Paid'])->assertCreated();
});

test('a tenant that has never chosen a plan is not blocked', function (): void {
    // Registration and onboarding must work before anyone picks a plan.
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    $owner = billingOwnerFor($tenant);

    $this->actingAs($owner);
    $this->postJson('/api/v1/branches', ['name' => 'Brand New'])->assertCreated();
});

/*
 * Plan ceilings drive upgrades, not discounts.
 */
test('every employee is charged for, even past the plan ceiling', function (): void {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    Employee::factory()->count(40)->create(['employment_status' => Employee::STATUS_ACTIVE]);

    $plan = Plan::factory()->create([
        'price_per_employee' => 100000,
        'min_employees' => 5,
        'max_employees' => 25,
    ]);

    $quote = app(BillingService::class)->quote($plan, $tenant->id);

    // Previously capped at 25 seats, which made the cheapest plan unlimited
    // at a discount.
    expect($quote['billable_seats'])->toBe(40)
        ->and($quote['amount'])->toBe(4000000)
        ->and($quote['exceeds_ceiling'])->toBeTrue()
        ->and($quote['ceiling'])->toBe(25);
});

test('a headcount inside the ceiling is not flagged', function (): void {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    Employee::factory()->count(10)->create(['employment_status' => Employee::STATUS_ACTIVE]);

    $plan = Plan::factory()->create(['min_employees' => 1, 'max_employees' => 25]);

    expect(app(BillingService::class)->quote($plan, $tenant->id)['exceeds_ceiling'])->toBeFalse();
});

test('the suggested upgrade is the cheapest plan that fits the headcount', function (): void {
    Plan::factory()->create(['name' => 'Starter', 'price_per_employee' => 100000, 'max_employees' => 25]);
    $growth = Plan::factory()->create(['name' => 'Growth', 'price_per_employee' => 150000, 'max_employees' => 200]);
    Plan::factory()->create(['name' => 'Enterprise', 'price_per_employee' => 250000, 'max_employees' => null]);

    // 40 staff outgrows Starter; Growth is the cheapest that fits.
    expect(app(BillingService::class)->suggestUpgrade(40)?->id)->toBe($growth->id);
});

test('a headcount beyond every ceiling falls to the unlimited plan', function (): void {
    Plan::factory()->create(['name' => 'Starter', 'price_per_employee' => 100000, 'max_employees' => 25]);
    $enterprise = Plan::factory()->create(['name' => 'Enterprise', 'price_per_employee' => 250000, 'max_employees' => null]);

    expect(app(BillingService::class)->suggestUpgrade(5000)?->id)->toBe($enterprise->id);
});

/*
 * Every new company lands on the billing ladder immediately.
 */
test('registering a company starts a trial on the entry plan', function (): void {
    Notification::fake();
    $plan = Plan::factory()->create(['name' => 'Starter', 'sort_order' => 1, 'trial_days' => 14]);
    Plan::factory()->create(['name' => 'Growth', 'sort_order' => 2]);

    $this->postJson('/api/v1/register', [
        'company_name' => 'Fresh Company',
        'name' => 'Ada Owner',
        'email' => 'ada@fresh.test',
        'password' => 'Sup3r-Secret!',
        'password_confirmation' => 'Sup3r-Secret!',
    ])->assertCreated();

    $tenant = Tenant::query()->where('name', 'Fresh Company')->firstOrFail();
    $subscription = Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

    // Cheapest tier, on trial — not left unsubscribed and unenforceable.
    expect($subscription->plan_id)->toBe($plan->id)
        ->and($subscription->status)->toBe(Subscription::STATUS_TRIALING)
        ->and($subscription->onTrial())->toBeTrue();
});

test('registration still works when no plans are configured', function (): void {
    Notification::fake();
    // A fresh install has an empty price list; sign-up must not depend on it.
    expect(Plan::query()->count())->toBe(0);

    $this->postJson('/api/v1/register', [
        'company_name' => 'No Plans Yet',
        'name' => 'Owner',
        'email' => 'owner@noplans.test',
        'password' => 'Sup3r-Secret!',
        'password_confirmation' => 'Sup3r-Secret!',
    ])->assertCreated();

    $tenant = Tenant::query()->where('name', 'No Plans Yet')->firstOrFail();
    expect(Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0);
});
