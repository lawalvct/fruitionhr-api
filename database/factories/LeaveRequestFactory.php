<?php

namespace Database\Factories;

use App\Modules\Leave\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeaveRequest> */
class LeaveRequestFactory extends Factory
{
    protected $model = LeaveRequest::class;

    public function definition(): array
    {
        return [
            'start_date' => '2026-07-06',
            'end_date' => '2026-07-08',
            'days' => 3,
            'reason' => fake()->sentence(),
            'status' => LeaveRequest::STATUS_PENDING,
        ];
    }
}
