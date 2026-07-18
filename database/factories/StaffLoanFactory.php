<?php

namespace Database\Factories;

use App\Modules\Employee\Models\Employee;
use App\Modules\Payroll\Models\StaffLoan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StaffLoan> */
class StaffLoanFactory extends Factory
{
    protected $model = StaffLoan::class;

    public function definition(): array
    {
        $principal = fake()->numberBetween(50_000_00, 500_000_00); // ₦50k–₦500k in kobo
        $months = fake()->numberBetween(2, 12);

        return [
            'employee_id' => Employee::factory(),
            'type' => StaffLoan::TYPE_LOAN,
            'principal' => $principal,
            'months' => $months,
            'monthly_installment' => (int) ceil($principal / $months),
            'balance' => $principal,
            'start_period' => now()->format('Y-m'),
            'status' => StaffLoan::STATUS_DRAFT,
            'reason' => fake()->randomElement(['Rent support', 'Medical', 'School fees', 'Personal']),
        ];
    }

    public function advance(int $principal): static
    {
        return $this->state(fn () => [
            'type' => StaffLoan::TYPE_ADVANCE,
            'principal' => $principal,
            'months' => 1,
            'monthly_installment' => $principal,
            'balance' => $principal,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => StaffLoan::STATUS_ACTIVE, 'disbursed_at' => now()]);
    }
}
