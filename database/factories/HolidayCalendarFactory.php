<?php

namespace Database\Factories;

use App\Modules\Company\Models\HolidayCalendar;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HolidayCalendar> */
class HolidayCalendarFactory extends Factory
{
    protected $model = HolidayCalendar::class;

    public function definition(): array
    {
        $year = (int) now()->format('Y');

        return [
            'year' => $year,
            'name' => 'Nigeria Public Holidays '.$year,
        ];
    }
}
