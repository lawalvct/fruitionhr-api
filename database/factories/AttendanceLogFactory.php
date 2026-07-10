<?php

namespace Database\Factories;

use App\Modules\Attendance\Models\AttendanceLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AttendanceLog> */
class AttendanceLogFactory extends Factory
{
    protected $model = AttendanceLog::class;

    public function definition(): array
    {
        return [
            'date' => fake()->date('Y-m-d'),
            'clock_in' => '08:00',
            'clock_out' => '17:00',
            'source' => AttendanceLog::SOURCE_MANUAL,
        ];
    }
}
