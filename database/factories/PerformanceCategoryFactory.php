<?php

namespace Database\Factories;

use App\Modules\Performance\Models\PerformanceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PerformanceCategory> */
class PerformanceCategoryFactory extends Factory
{
    protected $model = PerformanceCategory::class;
    public function definition(): array { return ['name' => fake()->unique()->words(2, true), 'description' => fake()->sentence(), 'is_active' => true]; }
}
