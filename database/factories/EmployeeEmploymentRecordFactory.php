<?php

namespace Database\Factories;

use App\Modules\Employee\Models\EmployeeEmploymentRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmployeeEmploymentRecord> */
class EmployeeEmploymentRecordFactory extends Factory
{
    protected $model = EmployeeEmploymentRecord::class;

    public function definition(): array
    {
        return [
            'effective_from' => now()->toDateString(),
            'effective_to' => null,
            'is_current' => true,
        ];
    }
}
