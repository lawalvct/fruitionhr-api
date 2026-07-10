<?php

namespace Database\Factories;

use App\Modules\Company\Models\HolidayDate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HolidayDate> */
class HolidayDateFactory extends Factory
{
    protected $model = HolidayDate::class;

    public function definition(): array
    {
        return [
            'date' => fake()->dateTimeBetween('now', '+1 year')->format('Y-m-d'),
            'name' => fake()->randomElement(['New Year Holiday', 'Workers Day', 'Democracy Day', 'Christmas Day']),
            'is_recurring' => false,
        ];
    }
}
