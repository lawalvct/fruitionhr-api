<?php

namespace Database\Factories;

use App\Modules\Employee\Models\Employee;
use App\Modules\Payroll\Models\OvertimePayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OvertimePayment> */
class OvertimePaymentFactory extends Factory
{
    protected $model = OvertimePayment::class;

    public function definition(): array
    {
        $hours = fake()->randomFloat(2, 1, 12);
        $hourlyRate = fake()->numberBetween(50_000, 300_000); // kobo/hour
        $multiplier = fake()->randomElement([1, 1.5, 2]);

        return [
            'employee_id' => Employee::factory(),
            'period' => now()->format('Y-m'),
            'work_date' => now()->toDateString(),
            'source' => OvertimePayment::SOURCE_MANUAL,
            'pay_type' => OvertimePayment::PAY_TYPE_HOURLY,
            'hours' => $hours,
            'multiplier' => $multiplier,
            'hourly_rate' => $hourlyRate,
            'amount' => (int) round($hours * $hourlyRate * $multiplier),
            'disbursement_mode' => OvertimePayment::MODE_IN_PAYROLL,
            'status' => OvertimePayment::STATUS_DRAFT,
            'reason' => fake()->randomElement(['Weekend shift', 'After-hours work', 'Month-end close']),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => OvertimePayment::STATUS_APPROVED]);
    }

    public function offCycle(): static
    {
        return $this->state(fn () => ['disbursement_mode' => OvertimePayment::MODE_OFF_CYCLE]);
    }

    public function fixed(int $amount): static
    {
        return $this->state(fn () => [
            'pay_type' => OvertimePayment::PAY_TYPE_FIXED,
            'hours' => null,
            'hourly_rate' => null,
            'multiplier' => 1,
            'amount' => $amount,
        ]);
    }
}
