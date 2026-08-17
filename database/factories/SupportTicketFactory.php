<?php

namespace Database\Factories;

use App\Modules\Support\Models\SupportTicket;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupportTicket> */
class SupportTicketFactory extends Factory
{
    protected $model = SupportTicket::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'reference' => 'TKT-'.str_pad((string) $this->faker->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'subject' => rtrim($this->faker->sentence(5), '.'),
            'category' => 'other',
            'priority' => 'normal',
            'status' => SupportTicket::STATUS_OPEN,
            'last_customer_reply_at' => now(),
        ];
    }
}
