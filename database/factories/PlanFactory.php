<?php

namespace Database\Factories;

use App\Modules\Billing\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Plan> */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = $this->faker->unique()->word();

        return [
            'name' => ucfirst($name),
            'slug' => Str::slug($name).'-'.$this->faker->unique()->numberBetween(1, 99999),
            'description' => $this->faker->sentence(),
            'price_per_employee' => 150000, // ₦1,500 per employee
            'billing_interval' => Plan::INTERVAL_MONTHLY,
            'min_employees' => 1,
            'max_employees' => null,
            'trial_days' => 30,
            'features' => ['Payroll', 'Attendance'],
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
