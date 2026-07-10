<?php

namespace Database\Factories;

use App\Modules\Employee\Models\EmployeeBankAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmployeeBankAccount> */
class EmployeeBankAccountFactory extends Factory
{
    protected $model = EmployeeBankAccount::class;

    public function definition(): array
    {
        return [
            'bank_name' => fake()->randomElement(['GTBank', 'Access Bank', 'Zenith Bank', 'UBA']),
            'bank_code' => fake()->numerify('###'),
            'account_number' => fake()->numerify('##########'),
            'account_name' => fake()->name(),
            'is_primary' => true,
        ];
    }
}
