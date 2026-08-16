<?php

use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Tenancy\Models\Tenant;

/** The public price list feeds the marketing site, so it must not leak. */
test('anyone can read the active price list, in display order', function (): void {
    Plan::factory()->create(['name' => 'Growth', 'sort_order' => 2]);
    Plan::factory()->create(['name' => 'Starter', 'sort_order' => 1]);

    $response = $this->getJson('/api/v1/plans')->assertOk();

    expect(collect($response->json('data'))->pluck('name')->all())->toBe(['Starter', 'Growth']);
    expect($response->json('meta.currency'))->toBe('NGN');
});

test('retired plans are hidden from the public list', function (): void {
    Plan::factory()->create(['name' => 'Current', 'is_active' => true]);
    Plan::factory()->create(['name' => 'Retired', 'is_active' => false]);

    $names = collect($this->getJson('/api/v1/plans')->assertOk()->json('data'))->pluck('name');

    expect($names)->toContain('Current')->not->toContain('Retired');
});

test('the public list exposes prices but not commercial internals', function (): void {
    $plan = Plan::factory()->create(['price_per_employee' => 60000, 'min_employees' => 1]);
    $tenant = Tenant::factory()->create();
    Subscription::factory()->create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id]);

    $row = $this->getJson('/api/v1/plans')->assertOk()->json('data.0');

    expect($row['price_per_employee'])->toBe(60000)
        ->and($row)->toHaveKeys(['name', 'slug', 'features', 'trial_days', 'min_employees'])
        // How many customers are on a plan is nobody else's business.
        ->and($row)->not->toHaveKey('subscriptions_count')
        ->and($row)->not->toHaveKey('quote');
});

test('an unlimited plan reports max_employees as null rather than omitting it', function (): void {
    // The marketing page distinguishes "unlimited" from "unknown"; filtering
    // nulls out of the payload used to render "50–undefined employees".
    Plan::factory()->create(['min_employees' => 50, 'max_employees' => null]);

    $row = $this->getJson('/api/v1/plans')->assertOk()->json('data.0');

    expect($row)->toHaveKey('max_employees')
        ->and($row['max_employees'])->toBeNull()
        ->and($row)->toHaveKey('description');
});
