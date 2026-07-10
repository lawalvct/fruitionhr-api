<?php

namespace Database\Factories;

use App\Modules\Attendance\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Shift> */
class ShiftFactory extends Factory
{
    protected $model = Shift::class;

    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Day Shift', 'Morning', 'Standard']),
            'start_time' => '08:00',
            'end_time' => '17:00',
            'grace_minutes' => 15,
            'working_days' => [1, 2, 3, 4, 5], // Mon–Fri
            'is_active' => true,
        ];
    }
}
