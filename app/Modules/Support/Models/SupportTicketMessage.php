<?php

namespace App\Modules\Support\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\SupportTicketMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in a ticket thread.
 *
 * `is_internal` marks an agent-only note. Those are filtered out of every
 * customer-facing query — a leak here would show a company what we say about
 * them internally.
 */
class SupportTicketMessage extends Model
{
    /** @use HasFactory<SupportTicketMessageFactory> */
    use BelongsToTenant, HasFactory;

    protected static string $factory = SupportTicketMessageFactory::class;

    public const AUTHOR_CUSTOMER = 'customer';

    public const AUTHOR_AGENT = 'agent';

    protected $fillable = [
        'tenant_id', 'support_ticket_id', 'user_id', 'author_type', 'body', 'is_internal',
    ];

    protected function casts(): array
    {
        return ['is_internal' => 'boolean'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
