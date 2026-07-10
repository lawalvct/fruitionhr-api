<?php

namespace Database\Factories;

use App\Modules\Company\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Department> */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement(['People Operations', 'Finance', 'Sales', 'Engineering', 'Customer Success']).' '.fake()->numberBetween(1, 999);

        return [
            'name' => $name,
            'code' => Str::upper(fake()->unique()->bothify('DP-###')),
            'is_active' => true,
        ];
    }
}
