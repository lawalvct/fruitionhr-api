<?php

namespace Database\Factories;

use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Models\SupportTicketMessage;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupportTicketMessage> */
class SupportTicketMessageFactory extends Factory
{
    protected $model = SupportTicketMessage::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'support_ticket_id' => SupportTicket::factory(),
            'author_type' => SupportTicketMessage::AUTHOR_CUSTOMER,
            'body' => $this->faker->paragraph(),
            'is_internal' => false,
        ];
    }
}
