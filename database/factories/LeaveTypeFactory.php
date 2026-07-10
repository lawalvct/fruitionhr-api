<?php

namespace Database\Factories;

use App\Modules\Leave\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeaveType> */
class LeaveTypeFactory extends Factory
{
    protected $model = LeaveType::class;

    public function definition(): array
    {
        $name = fake()->randomElement(['Annual', 'Sick', 'Casual', 'Maternity']);

        return [
            'name' => $name.' Leave',
            'code' => strtoupper(substr($name, 0, 3)),
            'is_paid' => true,
            'requires_document' => false,
            'is_active' => true,
        ];
    }
}
