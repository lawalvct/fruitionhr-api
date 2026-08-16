<?php

use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Employee\Models\Employee;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Validation\ValidationException;

/**
 * FruitionHR charges per employee, so the headcount is the meter. Money is
 * integer kobo throughout — a float creeping in here would be a billing bug.
 */
function seedEmployees(Tenant $tenant, int $active, int $exited = 0): void
{
    app(CurrentTenant::class)->set($tenant);

    Employee::factory()->count($active)->create(['employment_status' => Employee::STATUS_ACTIVE]);

    if ($exited > 0) {
        Employee::factory()->count($exited)->create(['employment_status' => Employee::STATUS_EXITED]);
    }
}

test('staff who have exited are not billed for', function (): void {
    $tenant = Tenant::factory()->create();
    seedEmployees($tenant, active: 7, exited: 3);

    expect(app(BillingService::class)->billableEmployees($tenant->id))->toBe(7);
});

test('staff on leave or suspended still occupy a paid seat', function (): void {
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);

    Employee::factory()->create(['employment_status' => Employee::STATUS_ACTIVE]);
    Employee::factory()->create(['employment_status' => Employee::STATUS_ON_LEAVE]);
    Employee::factory()->create(['employment_status' => Employee::STATUS_SUSPENDED]);
    Employee::factory()->create(['employment_status' => Employee::STATUS_EXITED]);

    expect(app(BillingService::class)->billableEmployees($tenant->id))->toBe(3);
});

test('the price is headcount times the per-employee rate', function (): void {
    $tenant = Tenant::factory()->create();
    seedEmployees($tenant, active: 12);

    $plan = Plan::factory()->create([
        'price_per_employee' => 150000, // ₦1,500
        'min_employees' => 1,
        'max_employees' => null,
    ]);

    $quote = app(BillingService::class)->quote($plan, $tenant->id);

    expect($quote['employees'])->toBe(12)
        ->and($quote['billable_seats'])->toBe(12)
        ->and($quote['amount'])->toBe(1800000) // ₦18,000 in kobo
        ->and($quote['amount'])->toBeInt();
});

test('a company below the plan floor still pays for the minimum seats', function (): void {
    $tenant = Tenant::factory()->create();
    seedEmployees($tenant, active: 2);

    $plan = Plan::factory()->create(['price_per_employee' => 100000, 'min_employees' => 5]);

    $quote = app(BillingService::class)->quote($plan, $tenant->id);

    expect($quote['employees'])->toBe(2)
        ->and($quote['billable_seats'])->toBe(5)
        ->and($quote['amount'])->toBe(500000);
});

test('a plan ceiling caps what is charged', function (): void {
    $tenant = Tenant::factory()->create();
    seedEmployees($tenant, active: 40);

    $plan = Plan::factory()->create([
        'price_per_employee' => 100000,
        'min_employees' => 1,
        'max_employees' => 25,
    ]);

    $quote = app(BillingService::class)->quote($plan, $tenant->id);

    expect($quote['employees'])->toBe(40)->and($quote['billable_seats'])->toBe(25);
});

test('headcount is counted per tenant, never across the platform', function (): void {
    $alpha = Tenant::factory()->create();
    $beta = Tenant::factory()->create();
    seedEmployees($alpha, active: 4);
    seedEmployees($beta, active: 9);

    $billing = app(BillingService::class);

    expect($billing->billableEmployees($alpha->id))->toBe(4)
        ->and($billing->billableEmployees($beta->id))->toBe(9);
});

test('subscribing a new tenant starts a trial', function (): void {
    $tenant = Tenant::factory()->create();
    seedEmployees($tenant, active: 3);
    $plan = Plan::factory()->create(['trial_days' => 14, 'min_employees' => 1]);

    $subscription = app(BillingService::class)->subscribe($tenant, $plan);

    expect($subscription->status)->toBe(Subscription::STATUS_TRIALING)
        ->and($subscription->onTrial())->toBeTrue()
        ->and($subscription->isUsable())->toBeTrue()
        ->and($subscription->trial_ends_at->isFuture())->toBeTrue();
});

test('switching plans keeps the original trial rather than granting a new one', function (): void {
    $tenant = Tenant::factory()->create();
    seedEmployees($tenant, active: 3);
    $billing = app(BillingService::class);

    $first = Plan::factory()->create(['trial_days' => 14]);
    $original = $billing->subscribe($tenant, $first);
    $originalTrialEnd = $original->trial_ends_at;

    // Hop to a plan offering its own 30-day trial a few days later.
    $this->travel(3)->days();
    $second = Plan::factory()->create(['trial_days' => 30, 'price_per_employee' => 250000]);
    $switched = $billing->subscribe($tenant, $second);

    expect($switched->plan_id)->toBe($second->id)
        // The clock must not restart, or plan-hopping would be a free ride.
        ->and($switched->trial_ends_at->timestamp)->toBe($originalTrialEnd->timestamp)
        // ...but they are still mid-trial and must keep working.
        ->and($switched->onTrial())->toBeTrue()
        ->and($switched->isUsable())->toBeTrue();

    // Still one subscription row, not two.
    expect(Subscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('an inactive plan cannot be subscribed to', function (): void {
    $tenant = Tenant::factory()->create();
    $plan = Plan::factory()->create(['is_active' => false]);

    app(BillingService::class)->subscribe($tenant, $plan);
})->throws(ValidationException::class);

test('a lapsed subscription is not usable', function (): void {
    $tenant = Tenant::factory()->create();
    $subscription = Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => Subscription::STATUS_EXPIRED,
        'current_period_end' => now()->subDay(),
    ]);

    expect($subscription->isUsable())->toBeFalse();
});

test('cancelling leaves access until the period the tenant paid for ends', function (): void {
    $tenant = Tenant::factory()->create();
    $subscription = Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => Subscription::STATUS_ACTIVE,
        'current_period_end' => now()->addDays(20),
    ]);

    $cancelled = app(BillingService::class)->cancel($subscription);

    expect($cancelled->status)->toBe(Subscription::STATUS_CANCELLED)
        ->and($cancelled->ends_at->isFuture())->toBeTrue()
        // Paid until the end of the month — not cut off on the spot.
        ->and($cancelled->isUsable())->toBeTrue();
});
