<?php

namespace Database\Factories;

use App\Modules\Employee\Models\EmployeeContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmployeeContact> */
class EmployeeContactFactory extends Factory
{
    protected $model = EmployeeContact::class;

    public function definition(): array
    {
        return [
            'type' => fake()->randomElement(['emergency', 'next_of_kin']),
            'name' => fake()->name(),
            'relationship' => fake()->randomElement(['Spouse', 'Sibling', 'Parent', 'Friend']),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->optional()->safeEmail(),
            'address' => fake()->address(),
        ];
    }
}
