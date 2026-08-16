<?php

namespace Database\Seeders;

use App\Modules\Billing\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Starting price list. Prices are in kobo and are meant to be edited from the
 * super-admin console — these are a sane default, not a business decision.
 *
 * Trials are 30 days on every plan, deliberately: that is long enough for a
 * company to run one full monthly payroll end to end. Seeing real payslips and
 * statutory deductions for their own staff is what actually earns their
 * confidence, and a 14-day trial ends before that ever happens.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'description' => 'For small teams getting their payroll in order.',
                'price_per_employee' => 100000, // ₦1,000 per employee / month
                'min_employees' => 5,
                'max_employees' => 25,
                'trial_days' => 30,
                'features' => ['Payroll & payslips', 'Employee records', 'Leave management', 'Email support'],
                'sort_order' => 1,
            ],
            [
                'name' => 'Growth',
                'slug' => 'growth',
                'description' => 'For growing companies that need attendance and performance.',
                'price_per_employee' => 150000, // ₦1,500
                'min_employees' => 10,
                'max_employees' => 200,
                'trial_days' => 30,
                'features' => ['Everything in Starter', 'Attendance & shifts', 'Performance reviews', 'Recruitment', 'Priority support'],
                'sort_order' => 2,
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'description' => 'For large organisations with complex structures.',
                'price_per_employee' => 250000, // ₦2,500
                'min_employees' => 50,
                'max_employees' => null,
                'trial_days' => 30,
                'features' => ['Everything in Growth', 'Unlimited employees', 'Advanced reporting', 'Dedicated account manager'],
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::query()->updateOrCreate(
                ['slug' => $plan['slug']],
                $plan + ['billing_interval' => Plan::INTERVAL_MONTHLY, 'is_active' => true],
            );
        }
    }
}
