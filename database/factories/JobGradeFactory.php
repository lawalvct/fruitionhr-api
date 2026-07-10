<?php

namespace Database\Factories;

use App\Modules\Company\Models\JobGrade;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<JobGrade> */
class JobGradeFactory extends Factory
{
    protected $model = JobGrade::class;

    public function definition(): array
    {
        $level = fake()->unique()->numberBetween(1, 99);

        return [
            'name' => 'Grade '.$level,
            'code' => Str::upper(fake()->unique()->bothify('JG-##')),
            'level' => $level,
            'min_salary' => fake()->numberBetween(15000000, 35000000),
            'max_salary' => fake()->numberBetween(40000000, 80000000),
            'is_active' => true,
        ];
    }
}
