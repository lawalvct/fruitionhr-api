<?php

use App\Modules\Payroll\Calculators\NhfCalculator;
use App\Modules\Payroll\Calculators\NsitfCalculator;
use App\Modules\Payroll\Calculators\PayeCalculator;
use App\Modules\Payroll\Calculators\PensionCalculator;
use App\Modules\Payroll\Support\StatutoryDefaults;

function payeConfig(): array
{
    return collect(StatutoryDefaults::all())->firstWhere('type', 'paye')['config'];
}

// ── PAYE ────────────────────────────────────────────────────────────────────

it('applies progressive bands to a known annual taxable income', function () {
    // Taxable ₦4,194,400 (419,440,000 kobo). Hand-computed:
    //  300k@7=21,000 + 300k@11=33,000 + 500k@15=75,000 + 500k@19=95,000
    //  + 1,600k@21=336,000 + 994,400@24=238,656  =>  ₦798,656
    $tax = app(PayeCalculator::class)->applyBands(payeConfig()['bands'], 419_440_000);

    expect($tax)->toBe(79_865_600); // ₦798,656 in kobo
});

it('computes monthly PAYE for a ₦500,000/month earner (verified by hand)', function () {
    // gross 50,000,000 kobo; pensionable pay basis: pension employee 36,000/mo,
    // nhf 6,250/mo. GI = 5,493,000; CRA = 1,298,600; taxable = 4,194,400;
    // annual tax = 798,656; monthly = round(798,656/12) = ₦66,554.67
    $monthly = app(PayeCalculator::class)->monthly(
        payeConfig(),
        monthlyGross: 50_000_000,      // ₦500,000
        monthlyPension: 3_600_000,     // ₦36,000 (8% of ₦450,000 pensionable)
        monthlyNhf: 625_000,           // ₦6,250 (2.5% of ₦250,000 basic)
    );

    expect($monthly)->toBe(6_655_467); // ₦66,554.67 in kobo
});

it('computes monthly PAYE for a ₦100,000/month earner where the CRA floor applies', function () {
    // GI = 1,074,000; CRA = 414,800; taxable = 659,200; annual tax = 62,880;
    // monthly = ₦5,240
    $monthly = app(PayeCalculator::class)->monthly(
        payeConfig(),
        monthlyGross: 10_000_000,   // ₦100,000
        monthlyPension: 800_000,    // ₦8,000
        monthlyNhf: 250_000,        // ₦2,500
    );

    expect($monthly)->toBe(524_000); // ₦5,240
});

it('never returns negative tax for very low earners', function () {
    $monthly = app(PayeCalculator::class)->monthly(
        payeConfig(), monthlyGross: 3_000_000, monthlyPension: 240_000, monthlyNhf: 75_000,
    );

    expect($monthly)->toBeGreaterThanOrEqual(0);
});

// ── Pension / NHF / NSITF ────────────────────────────────────────────────────

it('computes pension at 8% employee and 10% employer of pensionable pay', function () {
    $config = ['employee_percent' => 8.0, 'employer_percent' => 10.0];
    $calc = app(PensionCalculator::class);

    expect($calc->employee($config, 45_000_000))->toBe(3_600_000)   // 8% of ₦450,000
        ->and($calc->employer($config, 45_000_000))->toBe(4_500_000); // 10% of ₦450,000
});

it('computes NHF at 2.5% of basic', function () {
    expect(app(NhfCalculator::class)->monthly(['percent' => 2.5], 25_000_000))
        ->toBe(625_000); // 2.5% of ₦250,000
});

it('computes NSITF at 1% of gross', function () {
    expect(app(NsitfCalculator::class)->monthly(['percent' => 1.0], 50_000_000))
        ->toBe(500_000); // 1% of ₦500,000
});
