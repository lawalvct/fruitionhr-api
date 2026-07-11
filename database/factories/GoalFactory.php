<?php

namespace Database\Factories;

use App\Modules\Performance\Models\Goal;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Goal> */
class GoalFactory extends Factory
{
    protected $model = Goal::class;
    public function definition(): array { return ['level' => 'company', 'title' => fake()->sentence(4), 'description' => fake()->sentence(), 'weight' => 20, 'progress' => 0, 'status' => 'active', 'due_at' => now()->addMonths(3)]; }
}
