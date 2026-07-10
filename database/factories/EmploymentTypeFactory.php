<?php

namespace Database\Factories;

use App\Modules\Company\Models\EmploymentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmploymentType> */
class EmploymentTypeFactory extends Factory
{
    protected $model = EmploymentType::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Full-time', 'Contract', 'Intern', 'Part-time', 'Consultant']),
            'is_active' => true,
        ];
    }
}
