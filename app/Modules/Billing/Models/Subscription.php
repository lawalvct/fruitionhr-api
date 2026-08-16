<?php

namespace App\Modules\Billing\Models;

use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tenant's current standing with FruitionHR. Tenant-owned so a company can
 * read its own billing; the platform console drops the scope deliberately.
 */
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use BelongsToTenant, HasFactory;

    protected static string $factory = SubscriptionFactory::class;

    public const STATUS_TRIALING = 'trialing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'tenant_id', 'plan_id', 'status', 'trial_ends_at', 'current_period_start',
        'current_period_end', 'cancelled_at', 'ends_at', 'employee_count', 'amount',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancelled_at' => 'datetime',
            'ends_at' => 'datetime',
            'employee_count' => 'integer',
            'amount' => 'integer',
        ];
    }

    public function tenantRecord(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function onTrial(): bool
    {
        return $this->status === self::STATUS_TRIALING
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isFuture();
    }

    /** Whether the tenant may currently use paid features. */
    public function isUsable(): bool
    {
        if ($this->onTrial()) {
            return true;
        }

        if ($this->status === self::STATUS_ACTIVE) {
            return $this->current_period_end === null || $this->current_period_end->isFuture();
        }

        // A cancelled subscription runs to the end of the paid period.
        if ($this->status === self::STATUS_CANCELLED) {
            return $this->ends_at !== null && $this->ends_at->isFuture();
        }

        return false;
    }
}
