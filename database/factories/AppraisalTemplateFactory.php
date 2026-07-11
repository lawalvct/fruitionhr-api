<?php

namespace Database\Factories;

use App\Modules\Performance\Models\AppraisalTemplate;
use App\Modules\Performance\Models\RatingScale;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AppraisalTemplate> */
class AppraisalTemplateFactory extends Factory
{
    protected $model = AppraisalTemplate::class;
    public function definition(): array
    {
        return ['rating_scale_id' => fn () => RatingScale::query()->create(['name' => fake()->unique()->words(2, true)])->id, 'name' => fake()->unique()->words(3, true), 'is_active' => true];
    }
}
