<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Employee\Models\Employee;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pricing and subscription lifecycle.
 *
 * FruitionHR charges per employee, so the headcount is the meter. Everything
 * here works in integer kobo.
 */
class BillingService
{
    /**
     * Employees a tenant is billed for.
     *
     * Everyone still on the books counts — someone on leave or suspended is
     * still employed and still occupies a seat. Only staff who have exited
     * stop being charged for. Soft-deleted records fall out automatically.
     *
     * Reads across the tenant scope explicitly so this works from the platform
     * console and from queued jobs alike.
     */
    public function billableEmployees(int $tenantId): int
    {
        return Employee::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('employment_status', '!=', Employee::STATUS_EXITED)
            ->count();
    }

    /**
     * What a tenant would pay right now on a given plan.
     *
     * Every employee is charged for, even past the plan ceiling — the ceiling
     * drives an upgrade prompt, not a discount.
     *
     * @return array{employees: int, billable_seats: int, unit_price: int, amount: int, exceeds_ceiling: bool, ceiling: ?int}
     */
    public function quote(Plan $plan, int $tenantId): array
    {
        $employees = $this->billableEmployees($tenantId);
        $seats = $plan->billableSeats($employees);

        return [
            'employees' => $employees,
            'billable_seats' => $seats,
            'unit_price' => $plan->price_per_employee,
            'amount' => $seats * $plan->price_per_employee,
            'exceeds_ceiling' => $plan->exceedsCeiling($employees),
            'ceiling' => $plan->max_employees,
        ];
    }

    /**
     * The cheapest active plan that comfortably fits this headcount — what to
     * offer someone who has outgrown their current tier.
     */
    public function suggestUpgrade(int $employeeCount): ?Plan
    {
        return Plan::query()
            ->where('is_active', true)
            ->where(function ($query) use ($employeeCount): void {
                $query->whereNull('max_employees')
                    ->orWhere('max_employees', '>=', $employeeCount);
            })
            ->orderBy('price_per_employee')
            ->orderBy('sort_order')
            ->first();
    }

    /**
     * The tenant's current subscription record, whatever state it is in.
     *
     * Expired and cancelled subscriptions are included deliberately. Omitting
     * them would make a lapsed tenant indistinguishable from one that never
     * subscribed — which reads as "no plan yet" on the billing page and, worse,
     * lets enforcement wave them straight through.
     */
    /**
     * The plan a brand-new tenant starts on: the cheapest active tier.
     *
     * Null when no plans are configured yet — a fresh install must still be
     * able to register companies.
     */
    public function defaultPlan(): ?Plan
    {
        return Plan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('price_per_employee')
            ->first();
    }

    public function activeSubscription(int $tenantId): ?Subscription
    {
        return Subscription::query()
            ->withoutGlobalScope(TenantScope::class)
            ->with('plan')
            ->where('tenant_id', $tenantId)
            ->latest('id')
            ->first();
    }

    /**
     * Put a tenant on a plan. Starts a trial when the plan offers one and the
     * tenant has not had a subscription before.
     */
    public function subscribe(Tenant $tenant, Plan $plan): Subscription
    {
        return DB::transaction(function () use ($tenant, $plan): Subscription {
            if (! $plan->is_active) {
                throw ValidationException::withMessages([
                    'plan' => 'That plan is no longer available.',
                ]);
            }

            $existing = $this->activeSubscription($tenant->id);
            $quote = $this->quote($plan, $tenant->id);

            // Only a first-time subscriber gets a trial. Someone switching plans
            // keeps the trial they are already on — its original end date, not a
            // fresh one — otherwise hopping plans would renew the free period
            // forever. Wiping it here would also strand them mid-trial with no
            // end date, which reads as "not usable".
            $isFirst = $existing === null;
            $trialEndsAt = $isFirst && $plan->trial_days > 0
                ? now()->addDays($plan->trial_days)
                : $existing?->trial_ends_at;

            $attributes = [
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'status' => $existing?->status
                    ?? ($trialEndsAt !== null ? Subscription::STATUS_TRIALING : Subscription::STATUS_PAST_DUE),
                'trial_ends_at' => $trialEndsAt,
                'employee_count' => $quote['employees'],
                'amount' => $quote['amount'],
            ];

            if ($isFirst && $trialEndsAt !== null) {
                $attributes['current_period_start'] = now();
                $attributes['current_period_end'] = $trialEndsAt;
            } elseif ($existing !== null) {
                // Keep the paid-through date when moving between plans.
                $attributes['current_period_start'] = $existing->current_period_start;
                $attributes['current_period_end'] = $existing->current_period_end;
            }

            if ($existing !== null) {
                $existing->forceFill($attributes)->save();

                return $existing->refresh()->load('plan');
            }

            $subscription = new Subscription;
            $subscription->forceFill($attributes)->save();

            return $subscription->refresh()->load('plan');
        });
    }

    /**
     * Mark a period paid and roll the window forward. Called once a payment is
     * confirmed, never from a callback parameter.
     */
    public function recordSuccessfulPayment(Payment $payment): ?Subscription
    {
        $subscription = $payment->subscription_id === null
            ? $this->activeSubscription($payment->tenant_id)
            : Subscription::query()
                ->withoutGlobalScope(TenantScope::class)
                ->with('plan')
                ->find($payment->subscription_id);

        if ($subscription === null) {
            return null;
        }

        $plan = $subscription->plan;
        $start = $this->nextPeriodStart($subscription);

        $subscription->forceFill([
            'status' => Subscription::STATUS_ACTIVE,
            'current_period_start' => $start,
            'current_period_end' => $start->copy()->addMonths($plan?->months() ?? 1),
            'employee_count' => $payment->employee_count ?: $subscription->employee_count,
            'amount' => $payment->amount,
            'cancelled_at' => null,
            'ends_at' => null,
        ])->save();

        return $subscription->refresh()->load('plan');
    }

    /** Cancels at period end rather than cutting access off immediately. */
    public function cancel(Subscription $subscription): Subscription
    {
        $subscription->forceFill([
            'status' => Subscription::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'ends_at' => $subscription->current_period_end ?? now(),
        ])->save();

        return $subscription->refresh()->load('plan');
    }

    /**
     * Renewals start when the current period ends, unless that is already in
     * the past — then the clock restarts now rather than back-dating.
     */
    private function nextPeriodStart(Subscription $subscription): Carbon
    {
        $end = $subscription->current_period_end;

        return $end !== null && $end->isFuture() ? $end->copy() : now();
    }
}
