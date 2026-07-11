<?php

use App\Models\User;
use App\Modules\Payroll\Models\StatutoryRule;
use App\Modules\Payroll\Support\SalaryBreakdown;
use App\Modules\Payroll\Support\StatutoryCalculator;
use App\Modules\Payroll\Support\StatutoryProvisioner;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;

function statutoryTenant(): Tenant
{
    $tenant = Tenant::factory()->create();
    app(CurrentTenant::class)->set($tenant);
    app(StatutoryProvisioner::class)->provision($tenant);

    return $tenant;
}

test('the orchestrator computes all statutory figures from a salary breakdown', function () {
    statutoryTenant();

    // ₦500,000 gross: basic ₦250,000, ₦200,000 pensionable allowances,
    // ₦50,000 non-pensionable-but-taxable. pensionable pay = ₦450,000.
    $breakdown = new SalaryBreakdown(
        basic: 25_000_000,
        earnings: [
            ['code' => 'HOU', 'name' => 'Housing', 'amount' => 12_500_000, 'is_taxable' => true, 'is_pensionable' => true],
            ['code' => 'TRA', 'name' => 'Transport', 'amount' => 7_500_000, 'is_taxable' => true, 'is_pensionable' => true],
            ['code' => 'MEAL', 'name' => 'Meal', 'amount' => 5_000_000, 'is_taxable' => true, 'is_pensionable' => false],
        ],
        deductions: [],
    );

    $result = app(StatutoryCalculator::class)->compute($breakdown, '2026-07');

    expect($breakdown->gross())->toBe(50_000_000)
        ->and($breakdown->pensionablePay())->toBe(45_000_000)
        ->and($result->pensionEmployee)->toBe(3_600_000)  // 8% of ₦450,000
        ->and($result->pensionEmployer)->toBe(4_500_000)  // 10% of ₦450,000
        ->and($result->nhf)->toBe(625_000)                // 2.5% of ₦250,000
        ->and($result->paye)->toBe(6_655_467)             // hand-verified
        ->and($result->nsitf)->toBe(500_000);             // 1% of ₦500,000

    // Only PAYE + employee pension + NHF reduce net.
    expect($result->employeeDeductions())->toBe(6_655_467 + 3_600_000 + 625_000);
});

test('registration provisions default statutory rules for the tenant', function () {
    $this->postJson('/api/v1/register', [
        'company_name' => 'Tax Co', 'name' => 'Ada', 'email' => 'ada@taxco.test',
        'password' => 'Sup3r-Secret!', 'password_confirmation' => 'Sup3r-Secret!',
    ])->assertCreated();

    $tenant = Tenant::query()->where('slug', 'tax-co')->firstOrFail();
    app(CurrentTenant::class)->set($tenant);

    expect(StatutoryRule::query()->pluck('type')->sort()->values()->all())
        ->toBe(['nhf', 'nsitf', 'paye', 'pension']);
});

test('statutory rules are tenant isolated', function () {
    statutoryTenant();

    $other = Tenant::factory()->create();
    app(CurrentTenant::class)->set($other);

    // No rules provisioned for the other tenant → resolving throws.
    expect(fn () => app(StatutoryCalculator::class)->configsFor('2026-07'))
        ->toThrow(RuntimeException::class);
});
