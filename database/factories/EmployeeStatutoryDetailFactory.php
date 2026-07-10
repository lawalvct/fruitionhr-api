<?php

namespace Database\Factories;

use App\Modules\Employee\Models\EmployeeStatutoryDetail;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmployeeStatutoryDetail> */
class EmployeeStatutoryDetailFactory extends Factory
{
    protected $model = EmployeeStatutoryDetail::class;

    public function definition(): array
    {
        return [
            'tax_id' => fake()->numerify('##########'),
            'pension_pin' => fake()->bothify('PEN########'),
            'pension_fund_administrator' => fake()->company(),
            'nhf_number' => fake()->numerify('NHF######'),
        ];
    }
}
