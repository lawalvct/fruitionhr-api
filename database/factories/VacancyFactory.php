<?php

namespace Database\Factories;

use App\Modules\Recruitment\Models\ManpowerRequisition;
use App\Modules\Recruitment\Models\Vacancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Vacancy> */
class VacancyFactory extends Factory
{
    protected $model = Vacancy::class;

    public function definition(): array
    {
        return [
            'manpower_requisition_id' => ManpowerRequisition::factory(),
            'title' => fake()->jobTitle(),
            'code' => strtoupper(fake()->unique()->lexify('VAC-???')),
            'description' => fake()->paragraph(),
            'positions_available' => 1,
            'status' => Vacancy::STATUS_DRAFT,
        ];
    }
}
