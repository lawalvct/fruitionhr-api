<?php

namespace App\Modules\Admin\Services;

use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * The commercial picture of one company, for the admin's company page.
 *
 * Contact details say who they are; this says how they are doing — what they
 * are on, whether they are paying, and whether they are stuck waiting on us.
 *
 * Every query drops the tenant scope explicitly. Subscription, Payment and
 * SupportTicket all use BelongsToTenant, whose scope fails closed, so without
 * this each company would report as having nothing at all.
 */
class PlatformCustomerSnapshot
{
    /** Tickets still needing someone on our side to act or follow up. */
    private const UNRESOLVED = [
        SupportTicket::STATUS_OPEN,
        SupportTicket::STATUS_IN_PROGRESS,
        SupportTicket::STATUS_WAITING_ON_CUSTOMER,
    ];

    /**
     * @param  bool  $includeRevenue  Whether the viewer holds the revenue ability.
     *                                Money is a separate permission from company
     *                                administration, and this is where that line
     *                                is actually drawn for the detail page.
     * @return array<string, mixed>
     */
    public function for(Tenant $tenant, bool $includeRevenue): array
    {
        $subscription = Subscription::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->with('plan:id,name,billing_interval')
            ->latest('id')
            ->first();

        $snapshot = [
            'subscription' => $subscription === null ? null : [
                'status' => $subscription->status,
                'plan' => $subscription->plan?->name,
                'billing_interval' => $subscription->plan?->billing_interval,
                'employee_count' => (int) $subscription->employee_count,
                'on_trial' => $subscription->onTrial(),
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
            ],
            'support' => [
                'unresolved' => $this->tickets($tenant)->whereIn('status', self::UNRESOLVED)->count(),
                'total' => $this->tickets($tenant)->count(),
            ],
        ];

        if (! $includeRevenue) {
            return $snapshot;
        }

        $lastPayment = Payment::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->where('status', Payment::STATUS_SUCCESSFUL)
            ->latest('paid_at')
            ->first();

        $snapshot['revenue'] = [
            // What they are contracted to pay each period.
            'amount' => (int) ($subscription->amount ?? 0),
            // Money that has actually arrived from this company, ever.
            'collected' => (int) Payment::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenant->id)
                ->where('status', Payment::STATUS_SUCCESSFUL)
                ->sum('amount'),
            'last_payment_at' => $lastPayment?->paid_at?->toIso8601String(),
        ];

        return $snapshot;
    }

    /** @return Builder<SupportTicket> */
    private function tickets(Tenant $tenant): Builder
    {
        return SupportTicket::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id);
    }
}
