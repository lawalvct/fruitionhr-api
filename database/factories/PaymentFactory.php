<?php

namespace Database\Factories;

use App\Modules\Billing\Models\Payment;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'gateway' => 'paystack',
            'reference' => 'PST_'.strtoupper(Str::random(10)).'_'.$this->faker->unique()->numberBetween(1, 999999),
            'amount' => 1500000,
            'currency' => 'NGN',
            'status' => Payment::STATUS_PENDING,
            'employee_count' => 10,
        ];
    }
}
