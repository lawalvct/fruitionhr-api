<?php

use App\Models\User;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Tenancy\Models\Tenant;

test('an admin can see every tenant subscription with revenue totals', function (): void {
    $alpha = Tenant::factory()->create(['name' => 'Alpha Foods Ltd']);
    $beta = Tenant::factory()->create(['name' => 'Beta Logistics Ltd']);
    $plan = Plan::factory()->create(['name' => 'Growth']);

    Subscription::factory()->create([
        'tenant_id' => $alpha->id, 'plan_id' => $plan->id,
        'status' => Subscription::STATUS_ACTIVE, 'employee_count' => 12,
    ]);
    Subscription::factory()->create([
        'tenant_id' => $beta->id, 'plan_id' => $plan->id,
        'status' => Subscription::STATUS_TRIALING, 'employee_count' => 8,
    ]);

    foreach ([[$alpha, 'PST_1', Payment::STATUS_SUCCESSFUL], [$beta, 'PST_2', Payment::STATUS_FAILED]] as [$t, $ref, $status]) {
        $payment = new Payment;
        $payment->forceFill([
            'tenant_id' => $t->id, 'gateway' => 'paystack', 'reference' => $ref,
            'amount' => 1800000, 'status' => $status,
        ])->save();
    }

    $this->actingAs(User::factory()->platformAdministrator()->create());

    $response = $this->getJson('/api/admin/v1/billing/subscriptions')->assertOk();

    expect(collect($response->json('data'))->pluck('company.name'))
        ->toContain('Alpha Foods Ltd')->toContain('Beta Logistics Ltd');

    expect($response->json('summary.active'))->toBe(1)
        ->and($response->json('summary.trialing'))->toBe(1)
        // Only settled money counts as collected.
        ->and($response->json('summary.collected'))->toBe(1800000)
        ->and($response->json('summary.billable_employees'))->toBe(20);
});

test('an admin can create and reprice a plan', function (): void {
    $this->actingAs(User::factory()->platformAdministrator()->create());

    $created = $this->postJson('/api/admin/v1/billing/plans', [
        'name' => 'Scale',
        'price_per_employee' => 200000,
        'billing_interval' => 'monthly',
        'min_employees' => 10,
        'trial_days' => 14,
        'features' => ['Payroll', 'Attendance'],
    ])->assertCreated()->assertJsonPath('data.slug', 'scale');

    $this->putJson("/api/admin/v1/billing/plans/{$created->json('data.id')}", [
        'price_per_employee' => 220000,
    ])->assertOk()->assertJsonPath('data.price_per_employee', 220000);

    $this->assertDatabaseHas('platform_activities', ['action' => 'plan.updated']);
});

test('plan prices must be whole kobo, never a float', function (): void {
    $this->actingAs(User::factory()->platformAdministrator()->create());

    $this->postJson('/api/admin/v1/billing/plans', [
        'name' => 'Bad',
        'price_per_employee' => 1500.75, // naira-with-decimals is a billing bug
        'billing_interval' => 'monthly',
        'min_employees' => 1,
        'trial_days' => 0,
    ])->assertUnprocessable()->assertJsonValidationErrors('price_per_employee');
});

test('a plan ceiling below its floor is rejected', function (): void {
    $this->actingAs(User::factory()->platformAdministrator()->create());

    $this->postJson('/api/admin/v1/billing/plans', [
        'name' => 'Impossible',
        'price_per_employee' => 100000,
        'billing_interval' => 'monthly',
        'min_employees' => 50,
        'max_employees' => 10,
        'trial_days' => 0,
    ])->assertUnprocessable()->assertJsonValidationErrors('max_employees');
});

test('platform billing is closed to tenant users and guests', function (): void {
    $this->getJson('/api/admin/v1/billing/subscriptions')->assertUnauthorized();

    $this->actingAs(User::factory()->create());
    $this->getJson('/api/admin/v1/billing/subscriptions')->assertForbidden();
    $this->getJson('/api/admin/v1/billing/plans')->assertForbidden();
    $this->postJson('/api/admin/v1/billing/plans', [])->assertForbidden();
});

test('a plan whose name collides with an existing one is rejected cleanly', function (): void {
    Plan::factory()->create(['name' => 'Starter', 'slug' => 'starter']);

    $this->actingAs(User::factory()->platformAdministrator()->create());

    // No slug sent — it is derived from the name, so validation must still
    // catch the clash rather than letting the database throw a 500.
    $this->postJson('/api/admin/v1/billing/plans', [
        'name' => 'Starter',
        'price_per_employee' => 60000,
        'billing_interval' => 'monthly',
        'min_employees' => 1,
        'max_employees' => 20,
        'trial_days' => 14,
    ])->assertUnprocessable()->assertJsonValidationErrors('slug');
});

test('a differently named plan still gets a derived slug', function (): void {
    Plan::factory()->create(['name' => 'Starter', 'slug' => 'starter']);

    $this->actingAs(User::factory()->platformAdministrator()->create());

    $this->postJson('/api/admin/v1/billing/plans', [
        'name' => 'Starter Plus',
        'price_per_employee' => 60000,
        'billing_interval' => 'monthly',
        'min_employees' => 1,
        'trial_days' => 14,
    ])->assertCreated()->assertJsonPath('data.slug', 'starter-plus');
});

test('an explicit slug is still honoured and still checked', function (): void {
    Plan::factory()->create(['slug' => 'taken']);

    $this->actingAs(User::factory()->platformAdministrator()->create());

    $this->postJson('/api/admin/v1/billing/plans', [
        'name' => 'Anything',
        'slug' => 'taken',
        'price_per_employee' => 60000,
        'billing_interval' => 'monthly',
        'min_employees' => 1,
        'trial_days' => 14,
    ])->assertUnprocessable()->assertJsonValidationErrors('slug');
});

test('the plan list loads existing plans with their subscriber counts', function (): void {
    // Regression: Collection::mapInto() passes the array key as a second
    // constructor argument, which used to blow up PlanResource and 500 here.
    $starter = Plan::factory()->create(['name' => 'Starter', 'slug' => 'starter', 'sort_order' => 1]);
    Plan::factory()->create(['name' => 'Growth', 'slug' => 'growth', 'sort_order' => 2]);

    $tenant = Tenant::factory()->create();
    Subscription::factory()->create(['tenant_id' => $tenant->id, 'plan_id' => $starter->id]);

    $this->actingAs(User::factory()->platformAdministrator()->create());

    $response = $this->getJson('/api/admin/v1/billing/plans')->assertOk();

    expect(collect($response->json('data'))->pluck('name')->all())->toBe(['Starter', 'Growth']);
    expect(collect($response->json('data'))->firstWhere('name', 'Starter')['subscriptions_count'])->toBe(1);
});
