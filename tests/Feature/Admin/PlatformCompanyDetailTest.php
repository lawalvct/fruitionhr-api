<?php

use App\Models\User;
use App\Modules\Admin\Models\PlatformRole;
use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Authorization\PlatformAbilities;
use App\Support\Tenancy\CurrentTenant;

/**
 * The admin's page for one company.
 *
 * Two things are easy to get wrong here and neither is loud: the tenant scope
 * fails closed, so a missing withoutGlobalScope() reports an active customer as
 * having no subscription and no tickets; and the page is behind the `tenants`
 * ability, so anything commercial shown on it must respect `revenue` separately
 * or the split between those two permissions is decorative.
 */
function companyWithHistory(): Tenant
{
    $tenant = Tenant::factory()->create(['name' => 'Alpha Foods Ltd']);
    $plan = Plan::factory()->create(['name' => 'Growth', 'billing_interval' => Plan::INTERVAL_MONTHLY]);

    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => Subscription::STATUS_ACTIVE,
        'employee_count' => 24,
        'amount' => 2_400_000,
        'current_period_end' => now()->addDays(12),
    ]);

    foreach ([[1_400_000, Payment::STATUS_SUCCESSFUL], [1_000_000, Payment::STATUS_SUCCESSFUL], [9_000_000, Payment::STATUS_FAILED]] as $i => [$amount, $status]) {
        (new Payment)->forceFill([
            'tenant_id' => $tenant->id,
            'gateway' => 'paystack',
            'reference' => 'CD_'.$tenant->id.'_'.$i,
            'amount' => $amount,
            'status' => $status,
            'paid_at' => $status === Payment::STATUS_SUCCESSFUL ? now()->subDays($i) : null,
        ])->save();
    }

    // tenant_id explicitly: SupportTicketFactory creates its own tenant by
    // default, which would scatter these across three unrelated companies.
    app(CurrentTenant::class)->set($tenant);
    foreach ([SupportTicket::STATUS_OPEN, SupportTicket::STATUS_IN_PROGRESS, SupportTicket::STATUS_CLOSED] as $status) {
        SupportTicket::factory()->create(['tenant_id' => $tenant->id, 'status' => $status]);
    }
    app(CurrentTenant::class)->forget();

    return $tenant;
}

test('the company page shows what the customer is on and how they are doing', function (): void {
    $tenant = companyWithHistory();
    $this->actingAs(User::factory()->platformAdministrator()->create());

    $data = $this->getJson("/api/admin/v1/tenants/{$tenant->id}")->assertOk()->json('data');

    expect($data['name'])->toBe('Alpha Foods Ltd')
        ->and($data['subscription']['plan'])->toBe('Growth')
        ->and($data['subscription']['status'])->toBe(Subscription::STATUS_ACTIVE)
        ->and($data['subscription']['employee_count'])->toBe(24)
        ->and($data['subscription']['current_period_end'])->not->toBeNull()
        // Closed tickets are done; only the ones still needing us are counted.
        ->and($data['support']['unresolved'])->toBe(2)
        ->and($data['support']['total'])->toBe(3);
});

test('an owner sees the money, and only settled money counts', function (): void {
    $tenant = companyWithHistory();
    $this->actingAs(User::factory()->platformAdministrator()->create());

    $revenue = $this->getJson("/api/admin/v1/tenants/{$tenant->id}")->assertOk()->json('data.revenue');

    expect($revenue['amount'])->toBe(2_400_000)
        ->and($revenue['collected'])->toBe(2_400_000)
        ->and($revenue['last_payment_at'])->not->toBeNull();
});

test('company administration does not disclose what the company pays', function (): void {
    $tenant = companyWithHistory();

    // Someone who manages companies but has not been given revenue: they can
    // suspend and support this customer without seeing the books.
    $role = PlatformRole::factory()->granting([PlatformAbilities::TENANTS])->create();
    $this->actingAs(User::factory()->platformStaff($role)->create());

    $data = $this->getJson("/api/admin/v1/tenants/{$tenant->id}")->assertOk()->json('data');

    expect($data['subscription']['plan'])->toBe('Growth')
        ->and($data['support']['unresolved'])->toBe(2)
        ->and($data)->not->toHaveKey('revenue');

    // Belt and braces: no amount leaks anywhere else in the payload.
    expect(json_encode($data))->not->toContain('2400000');
});

test('a company with no subscription or tickets reports nothing rather than failing', function (): void {
    $tenant = Tenant::factory()->create(['name' => 'Brand New Ltd']);
    $this->actingAs(User::factory()->platformAdministrator()->create());

    $data = $this->getJson("/api/admin/v1/tenants/{$tenant->id}")->assertOk()->json('data');

    expect($data['subscription'])->toBeNull()
        ->and($data['support']['unresolved'])->toBe(0)
        ->and($data['revenue']['collected'])->toBe(0)
        ->and($data['revenue']['amount'])->toBe(0);
});

test('one company never reports another company history', function (): void {
    $alpha = companyWithHistory();
    $beta = Tenant::factory()->create(['name' => 'Beta Ltd']);

    $this->actingAs(User::factory()->platformAdministrator()->create());

    $data = $this->getJson("/api/admin/v1/tenants/{$beta->id}")->assertOk()->json('data');

    expect($data['subscription'])->toBeNull()
        ->and($data['support']['total'])->toBe(0)
        ->and($data['revenue']['collected'])->toBe(0)
        ->and($alpha->id)->not->toBe($beta->id);
});
