<?php

namespace App\Modules\Support\Models;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\SupportTicketFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A support request raised by a company. Tenant-owned so a company only ever
 * sees its own; the platform console drops the scope to work the queue.
 */
class SupportTicket extends Model
{
    /** @use HasFactory<SupportTicketFactory> */
    use BelongsToTenant, HasFactory;

    protected static string $factory = SupportTicketFactory::class;

    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_WAITING_ON_CUSTOMER = 'waiting_on_customer';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_PROGRESS,
        self::STATUS_WAITING_ON_CUSTOMER,
        self::STATUS_RESOLVED,
        self::STATUS_CLOSED,
    ];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const CATEGORIES = ['payroll', 'billing', 'attendance', 'account', 'technical', 'other'];

    protected $fillable = [
        'tenant_id', 'reference', 'subject', 'category', 'priority', 'status',
        'opened_by', 'assigned_to', 'last_customer_reply_at', 'last_agent_reply_at',
        'first_responded_at', 'resolved_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_customer_reply_at' => 'datetime',
            'last_agent_reply_at' => 'datetime',
            'first_responded_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->orderBy('created_at');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /** A closed ticket is finished; anything else can still be replied to. */
    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    /** Waiting on us rather than on the customer. */
    public function needsAgentAttention(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_IN_PROGRESS], true);
    }
}
