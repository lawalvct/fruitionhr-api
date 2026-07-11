<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Recruitment\Models\ManpowerRequisition;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ManpowerRequisition> */
class ManpowerRequisitionFactory extends Factory
{
    protected $model = ManpowerRequisition::class;

    public function definition(): array
    {
        return [
            'requested_by' => User::factory(),
            'title' => fake()->jobTitle(),
            'headcount' => fake()->numberBetween(1, 4),
            'target_start_date' => fake()->dateTimeBetween('+2 weeks', '+3 months'),
            'reason' => fake()->sentence(),
            'status' => ManpowerRequisition::STATUS_DRAFT,
        ];
    }
}
