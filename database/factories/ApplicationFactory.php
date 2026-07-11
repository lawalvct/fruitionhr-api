<?php

namespace Database\Factories;

use App\Modules\Recruitment\Models\Applicant;
use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Vacancy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Application> */
class ApplicationFactory extends Factory
{
    protected $model = Application::class;

    public function definition(): array
    {
        return [
            'vacancy_id' => Vacancy::factory(),
            'applicant_id' => Applicant::factory(),
            'stage' => 'applied',
            'source' => 'direct',
            'applied_at' => now(),
        ];
    }
}
