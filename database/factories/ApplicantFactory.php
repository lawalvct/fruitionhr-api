<?php

namespace Database\Factories;

use App\Modules\Recruitment\Models\Applicant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Applicant> */
class ApplicantFactory extends Factory
{
    protected $model = Applicant::class;

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'city' => fake()->city(),
            'state' => fake()->state(),
        ];
    }
}
