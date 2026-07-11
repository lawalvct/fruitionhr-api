<?php

namespace Database\Factories;

use App\Modules\Payroll\Models\SalaryComponent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SalaryComponent> */
class SalaryComponentFactory extends Factory
{
    protected $model = SalaryComponent::class;

    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Housing', 'Transport', 'Meal', 'Utility']).' Allowance',
            'code' => strtoupper(fake()->bothify('???###')),
            'type' => SalaryComponent::TYPE_EARNING,
            'calc_type' => SalaryComponent::CALC_FIXED,
            'percent' => null,
            'is_taxable' => true,
            'is_pensionable' => false,
            'is_active' => true,
        ];
    }

    public function percentOfBasic(int $percent): static
    {
        return $this->state([
            'calc_type' => SalaryComponent::CALC_PERCENT,
            'percent' => $percent,
        ]);
    }
}
