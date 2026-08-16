<?php

namespace Database\Factories;

use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Subscription> */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'plan_id' => Plan::factory(),
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => now()->subDays(5),
            'current_period_end' => now()->addDays(25),
            'employee_count' => 10,
            'amount' => 1500000,
        ];
    }

    public function trialing(): static
    {
        return $this->state(fn (): array => [
            'status' => Subscription::STATUS_TRIALING,
            'trial_ends_at' => now()->addDays(10),
            'current_period_end' => now()->addDays(10),
        ]);
    }
}
