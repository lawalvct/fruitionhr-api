<?php

namespace Database\Factories;

use App\Modules\Payroll\Models\SalaryStructure;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SalaryStructure> */
class SalaryStructureFactory extends Factory
{
    protected $model = SalaryStructure::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Junior', 'Senior', 'Management']).' Structure',
            'description' => null,
            'is_active' => true,
        ];
    }
}
