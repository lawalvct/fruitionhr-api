<?php

namespace App\Modules\Billing\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A price-list entry. Platform-owned — FruitionHR's own catalogue, so no
 * tenant scoping.
 *
 * @property int $price_per_employee kobo, per employee, per billing interval
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected static string $factory = PlanFactory::class;

    public const INTERVAL_MONTHLY = 'monthly';

    public const INTERVAL_YEARLY = 'yearly';

    protected $fillable = [
        'name', 'slug', 'description', 'price_per_employee', 'billing_interval',
        'min_employees', 'max_employees', 'trial_days', 'features', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_per_employee' => 'integer',
            'min_employees' => 'integer',
            'max_employees' => 'integer',
            'trial_days' => 'integer',
            'sort_order' => 'integer',
            'features' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Seats actually charged for: never fewer than the plan floor.
     *
     * max_employees is deliberately NOT applied here. It marks the point at
     * which a company has outgrown the plan and should be prompted to upgrade
     * — not a discount. Capping the bill at the ceiling would make the
     * cheapest plan unlimited-at-a-discount.
     */
    public function billableSeats(int $employeeCount): int
    {
        return max($employeeCount, $this->min_employees);
    }

    /** Whether this headcount has outgrown the plan and should upgrade. */
    public function exceedsCeiling(int $employeeCount): bool
    {
        return $this->max_employees !== null && $employeeCount > $this->max_employees;
    }

    /** Total for a headcount, in kobo. */
    public function priceFor(int $employeeCount): int
    {
        return $this->billableSeats($employeeCount) * $this->price_per_employee;
    }

    public function months(): int
    {
        return $this->billing_interval === self::INTERVAL_YEARLY ? 12 : 1;
    }
}
