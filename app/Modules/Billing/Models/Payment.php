<?php

namespace App\Modules\Billing\Models;

use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempted charge. `reference` is unique at the database level — that
 * constraint, not application logic, is what stops double fulfilment.
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use BelongsToTenant, HasFactory;

    protected static string $factory = PaymentFactory::class;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESSFUL = 'successful';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ABANDONED = 'abandoned';

    protected $fillable = [
        'tenant_id', 'subscription_id', 'gateway', 'reference', 'amount',
        'currency', 'status', 'employee_count', 'gateway_response', 'paid_at', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'employee_count' => 'integer',
            'gateway_response' => 'array',
            'paid_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
