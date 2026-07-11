<?php

namespace Database\Factories;

use App\Modules\Performance\Models\PerformanceCategory;
use App\Modules\Performance\Models\PerformanceKpi;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PerformanceKpi> */
class PerformanceKpiFactory extends Factory
{
    protected $model = PerformanceKpi::class;
    public function definition(): array { return ['performance_category_id' => PerformanceCategory::factory(), 'name' => fake()->sentence(3), 'measurement_unit' => 'percentage', 'is_active' => true]; }
}
