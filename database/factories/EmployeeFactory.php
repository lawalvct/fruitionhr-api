<?php

namespace Database\Factories;

use App\Modules\Employee\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Employee> */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'employee_number' => 'EMP-'.fake()->unique()->numberBetween(1000, 9999),
            'first_name' => fake()->firstName(),
            'middle_name' => null,
            'last_name' => fake()->lastName(),
            'official_email' => fake()->unique()->safeEmail(),
            'personal_email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'gender' => fake()->randomElement(['female', 'male']),
            'date_of_birth' => fake()->date('Y-m-d', '-22 years'),
            'marital_status' => fake()->randomElement(['single', 'married']),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->state(),
            'employment_status' => Employee::STATUS_ACTIVE,
            'hired_at' => fake()->date('Y-m-d', 'now'),
        ];
    }
}
