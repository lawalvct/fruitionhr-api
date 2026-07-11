<?php

namespace Database\Factories;

use App\Modules\Performance\Models\AppraisalCycle;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AppraisalCycle> */
class AppraisalCycleFactory extends Factory
{
    protected $model = AppraisalCycle::class;
    public function definition(): array { return ['name' => fake()->year().' Annual Review', 'starts_at' => now()->startOfYear(), 'ends_at' => now()->endOfYear(), 'status' => 'draft']; }
}
