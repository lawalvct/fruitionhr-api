<?php

use App\Modules\Payroll\Models\EmployeeSalaryComponentOverride;
use App\Modules\Payroll\Models\SalaryComponent;
use App\Modules\Payroll\Support\SalaryResolver;

/**
 * Build a lightweight structure-component line (as the resolver consumes it)
 * without touching the database.
 */
function line(SalaryComponent $component, ?int $amount = null, ?int $percent = null): object
{
    return (object) ['amount' => $amount, 'percent' => $percent, 'component' => $component];
}

function earning(array $attrs = []): SalaryComponent
{
    return new SalaryComponent(array_merge([
        'name' => 'Housing', 'code' => 'HOU', 'type' => 'earning',
        'calc_type' => 'fixed', 'is_taxable' => true, 'is_pensionable' => false,
    ], $attrs));
}

function employeeOverride(SalaryComponent $component, string $mode, ?int $amount = null): EmployeeSalaryComponentOverride
{
    $override = new EmployeeSalaryComponentOverride([
        'salary_component_id' => $component->id,
        'mode' => $mode,
        'amount' => $amount,
    ]);
    $override->setRelation('component', $component);

    return $override;
}

it('resolves fixed component overrides in kobo', function () {
    $breakdown = app(SalaryResolver::class)->resolve(20000000, [ // ₦200,000 basic
        line(earning(['code' => 'HOU', 'is_pensionable' => true]), amount: 5000000), // ₦50,000
        line(earning(['code' => 'TRA', 'is_pensionable' => true]), amount: 3000000), // ₦30,000
    ]);

    expect($breakdown->gross())->toBe(28000000)          // 200k + 50k + 30k
        ->and($breakdown->pensionablePay())->toBe(28000000) // all pensionable
        ->and($breakdown->taxablePay())->toBe(28000000);    // all taxable
});

it('resolves percent-of-basic via structure override', function () {
    $breakdown = app(SalaryResolver::class)->resolve(10000000, [ // ₦100,000
        line(earning(['code' => 'HOU']), percent: 25), // 25% of basic = ₦25,000
    ]);

    expect($breakdown->earnings[0]['amount'])->toBe(2500000)
        ->and($breakdown->gross())->toBe(12500000);
});

it('resolves percent from the component definition when no override', function () {
    $component = earning(['code' => 'HOU', 'calc_type' => 'percent_of_basic', 'percent' => 10]);

    $breakdown = app(SalaryResolver::class)->resolve(10000000, [line($component)]);

    expect($breakdown->earnings[0]['amount'])->toBe(1000000); // 10% of ₦100,000
});

it('ignores legacy basic salary components to prevent double counting', function () {
    $legacyBasic = earning(['name' => 'Basic Salary', 'code' => 'BASIC']);

    $breakdown = app(SalaryResolver::class)->resolve(60_000_000, [
        line($legacyBasic, amount: 6_000_000),
    ]);

    expect($breakdown->earnings)->toBeEmpty()
        ->and($breakdown->gross())->toBe(60_000_000)
        ->and($breakdown->taxablePay())->toBe(60_000_000)
        ->and($breakdown->pensionablePay())->toBe(60_000_000);
});

it('merges employee overrides additions and exclusions over structure defaults', function () {
    $transport = earning(['code' => 'TRA']);
    $housing = earning(['code' => 'HOU']);
    $meal = earning(['code' => 'MEAL']);

    $breakdown = app(SalaryResolver::class)->resolve(
        10_000_000,
        [line($transport, amount: 500_000), line($housing, amount: 1_000_000)],
        [
            employeeOverride($transport, EmployeeSalaryComponentOverride::MODE_OVERRIDE, 600_000),
            employeeOverride($housing, EmployeeSalaryComponentOverride::MODE_EXCLUDED),
            employeeOverride($meal, EmployeeSalaryComponentOverride::MODE_ADDITIONAL, 300_000),
        ],
    );

    expect($breakdown->gross())->toBe(10_900_000)
        ->and(collect($breakdown->earnings)->pluck('amount', 'code')->all())->toBe([
            'TRA' => 600_000,
            'MEAL' => 300_000,
        ]);
});

it('separates taxable and pensionable bases from gross', function () {
    $breakdown = app(SalaryResolver::class)->resolve(10000000, [
        line(earning(['code' => 'HOU', 'is_taxable' => true, 'is_pensionable' => true]), amount: 4000000),
        line(earning(['code' => 'NTX', 'is_taxable' => false, 'is_pensionable' => false]), amount: 2000000), // non-taxable allowance
    ]);

    expect($breakdown->gross())->toBe(16000000)         // 100k + 40k + 20k
        ->and($breakdown->taxablePay())->toBe(14000000)  // 100k + 40k (excludes 20k)
        ->and($breakdown->pensionablePay())->toBe(14000000);
});

it('collects deduction components separately from earnings', function () {
    $deduction = new SalaryComponent([
        'name' => 'Union Dues', 'code' => 'UNI', 'type' => 'deduction', 'calc_type' => 'fixed',
    ]);

    $breakdown = app(SalaryResolver::class)->resolve(10000000, [
        line(earning(['code' => 'HOU']), amount: 3000000),
        line($deduction, amount: 500000),
    ]);

    expect($breakdown->gross())->toBe(13000000)   // deductions don't affect gross
        ->and($breakdown->componentDeductions())->toBe(500000)
        ->and($breakdown->deductions)->toHaveCount(1);
});

it('keeps employer contributions out of employee gross and deductions', function () {
    $employerContribution = new SalaryComponent([
        'name' => 'Company Pension', 'code' => 'CP001',
        'type' => SalaryComponent::TYPE_EMPLOYER_CONTRIBUTOR,
        'calc_type' => SalaryComponent::CALC_PERCENT, 'percent' => 10,
        'is_taxable' => true, 'is_pensionable' => true,
    ]);

    $breakdown = app(SalaryResolver::class)->resolve(25_000_000, [line($employerContribution)]);

    expect($breakdown->employerContributions)->toHaveCount(1)
        ->and($breakdown->employerContributionTotal())->toBe(2_500_000)
        ->and($breakdown->gross())->toBe(25_000_000)
        ->and($breakdown->taxablePay())->toBe(25_000_000)
        ->and($breakdown->pensionablePay())->toBe(25_000_000)
        ->and($breakdown->componentDeductions())->toBe(0);
});

it('adds fringe benefits to taxable pay without treating them as cash earnings', function () {
    $fringeBenefit = new SalaryComponent([
        'name' => 'Company Car', 'code' => 'CAR',
        'type' => SalaryComponent::TYPE_FRINGE_BENEFIT,
        'calc_type' => SalaryComponent::CALC_FIXED,
        'is_taxable' => true, 'is_pensionable' => false,
    ]);

    $breakdown = app(SalaryResolver::class)->resolve(25_000_000, [
        line($fringeBenefit, amount: 5_000_000),
    ]);

    expect($breakdown->fringeBenefits)->toHaveCount(1)
        ->and($breakdown->gross())->toBe(25_000_000)
        ->and($breakdown->taxablePay())->toBe(30_000_000)
        ->and($breakdown->pensionablePay())->toBe(25_000_000)
        ->and($breakdown->componentDeductions())->toBe(0);
});

it('rounds percentage calculations to the nearest kobo', function () {
    // 33% of ₦1,000.01 (100001 kobo) = 33000.33 → 33000 kobo
    $breakdown = app(SalaryResolver::class)->resolve(100001, [
        line(earning(['code' => 'X']), percent: 33),
    ]);

    expect($breakdown->earnings[0]['amount'])->toBe(33000);
});
