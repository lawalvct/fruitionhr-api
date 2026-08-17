<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Payment;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What FruitionHR earns, across every tenant.
 *
 * Two different questions live here and are deliberately never added together:
 *
 *   Collected — money that actually arrived. Successful payments, by paid_at.
 *   Expected  — money contracted to arrive. Active subscriptions, by renewal.
 *
 * A trial is neither. It is shown separately as pipeline, because counting an
 * unconverted trial as income is how a forecast starts lying to its owner.
 *
 * Every query escapes the tenant scope explicitly. Payment and Subscription
 * both use BelongsToTenant, whose scope fails closed — without
 * withoutGlobalScope() every figure on this page would silently read zero.
 */
class RevenueService
{
    /** Statuses that represent money genuinely under contract. */
    private const EARNING_STATUSES = [Subscription::STATUS_ACTIVE];

    /** @return array<string, mixed> */
    public function overview(int $trendMonths = 12): array
    {
        $now = now();
        $activeSubscriptions = $this->subscriptions()
            ->whereIn('status', self::EARNING_STATUSES)
            ->with('plan')
            ->get();

        $mrr = $this->monthlyRecurring($activeSubscriptions);

        return [
            'generated_at' => $now->toIso8601String(),
            'collected' => [
                'this_month' => $this->collectedBetween($now->copy()->startOfMonth(), $now->copy()->endOfMonth()),
                'last_month' => $this->collectedBetween(
                    $now->copy()->subMonthNoOverflow()->startOfMonth(),
                    $now->copy()->subMonthNoOverflow()->endOfMonth(),
                ),
                'this_year' => $this->collectedBetween($now->copy()->startOfYear(), $now->copy()->endOfYear()),
                'all_time' => (int) $this->payments()->where('status', Payment::STATUS_SUCCESSFUL)->sum('amount'),
            ],
            'recurring' => [
                'mrr' => $mrr,
                // Straight multiple of MRR, not a promise about the next twelve
                // months — it moves the moment anyone upgrades or churns.
                'arr' => $mrr * 12,
                'paying_companies' => $activeSubscriptions->count(),
                'average_per_company' => $activeSubscriptions->count() > 0
                    ? intdiv($mrr, $activeSubscriptions->count())
                    : 0,
            ],
            'expected' => [
                // Renewals actually falling due, which is a firmer number than
                // MRR: it counts what the calendar says is coming.
                'next_30_days' => $this->renewalsDueWithin(30),
                'next_90_days' => $this->renewalsDueWithin(90),
                // Contingent, never added to expected income.
                'trial_pipeline' => (int) $this->subscriptions()
                    ->where('status', Subscription::STATUS_TRIALING)
                    ->sum('amount'),
                'trials_converting_soon' => $this->subscriptions()
                    ->where('status', Subscription::STATUS_TRIALING)
                    ->whereNotNull('trial_ends_at')
                    ->whereBetween('trial_ends_at', [$now, $now->copy()->addDays(14)])
                    ->count(),
                // Contracted but not arriving — the number worth chasing.
                'at_risk' => (int) $this->subscriptions()
                    ->where('status', Subscription::STATUS_PAST_DUE)
                    ->sum('amount'),
                'at_risk_companies' => $this->subscriptions()
                    ->where('status', Subscription::STATUS_PAST_DUE)
                    ->count(),
            ],
            'monthly_trend' => $this->monthlyTrend($trendMonths),
            'by_plan' => $this->byPlan($activeSubscriptions),
        ];
    }

    /**
     * Collected revenue per month, oldest first.
     *
     * Bucketed in PHP rather than SQL: DATE_FORMAT is MySQL-only and the test
     * suite runs on SQLite. Mirrors PlatformTenantService::companyGrowth().
     *
     * @return list<array{period: string, label: string, amount: int, payments: int}>
     */
    public function monthlyTrend(int $months = 12): array
    {
        $firstMonth = now()->startOfMonth()->subMonths($months - 1);

        $payments = $this->payments()
            ->where('status', Payment::STATUS_SUCCESSFUL)
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $firstMonth)
            ->get(['amount', 'paid_at'])
            ->groupBy(fn (Payment $payment): string => $payment->paid_at->format('Y-m'));

        return collect(range(0, $months - 1))
            ->map(function (int $offset) use ($firstMonth, $payments): array {
                $month = $firstMonth->copy()->addMonths($offset);
                $period = $month->format('Y-m');
                $bucket = $payments->get($period);

                return [
                    'period' => $period,
                    'label' => $month->format('M'),
                    'amount' => (int) ($bucket?->sum('amount') ?? 0),
                    'payments' => $bucket?->count() ?? 0,
                ];
            })
            ->all();
    }

    /**
     * Revenue per company, so the owner can see where it actually comes from.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Tenant>
     */
    public function byCompany(array $filters): LengthAwarePaginator
    {
        $successful = fn (Builder $query) => $query->where('status', Payment::STATUS_SUCCESSFUL);

        $query = Tenant::query()
            ->select(['id', 'name', 'slug', 'status', 'created_at'])
            ->withSum(['payments' => $successful], 'amount')
            ->withCount(['payments' => $successful])
            ->with(['subscriptions' => fn ($relation) => $relation
                ->withoutGlobalScope(TenantScope::class)
                ->whereIn('status', [
                    Subscription::STATUS_ACTIVE,
                    Subscription::STATUS_TRIALING,
                    Subscription::STATUS_PAST_DUE,
                ])
                ->with('plan')
                ->latest('id')
                ->limit(1)]);

        if (($filters['search'] ?? null) !== null) {
            $search = trim((string) $filters['search']);
            $query->where(fn (Builder $builder) => $builder
                ->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%"));
        }

        // Companies that have never paid are noise on a revenue page unless
        // they are explicitly asked for.
        if (($filters['paying_only'] ?? false)) {
            $query->whereHas('payments', $successful);
        }

        [$column, $direction] = match ((string) ($filters['sort'] ?? '-collected')) {
            'collected' => ['payments_sum_amount', 'asc'],
            'name' => ['name', 'asc'],
            '-name' => ['name', 'desc'],
            default => ['payments_sum_amount', 'desc'],
        };

        return $query
            ->orderBy($column, $direction)
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->appends($filters);
    }

    /**
     * Normalised monthly value of the subscriptions given.
     *
     * Yearly plans are divided by twelve so a mixed book still adds up to one
     * comparable monthly figure. Integer division drops at most a kobo per
     * subscription per month, which is the right way to round revenue: down.
     *
     * @param  Collection<int, Subscription>  $subscriptions
     */
    private function monthlyRecurring(Collection $subscriptions): int
    {
        return $subscriptions->sum(function (Subscription $subscription): int {
            $amount = (int) $subscription->amount;

            return $subscription->plan?->billing_interval === Plan::INTERVAL_YEARLY
                ? intdiv($amount, 12)
                : $amount;
        });
    }

    /** @param  Collection<int, Subscription>  $subscriptions */
    private function byPlan(Collection $subscriptions): array
    {
        return $subscriptions
            ->groupBy(fn (Subscription $subscription): string => $subscription->plan?->name ?? 'No plan')
            ->map(fn (Collection $group, string $name): array => [
                'plan' => $name,
                'companies' => $group->count(),
                'mrr' => $this->monthlyRecurring($group),
                'employees' => (int) $group->sum('employee_count'),
            ])
            ->sortByDesc('mrr')
            ->values()
            ->all();
    }

    private function collectedBetween(\DateTimeInterface $from, \DateTimeInterface $to): int
    {
        return (int) $this->payments()
            ->where('status', Payment::STATUS_SUCCESSFUL)
            ->whereBetween('paid_at', [$from, $to])
            ->sum('amount');
    }

    private function renewalsDueWithin(int $days): int
    {
        return (int) $this->subscriptions()
            ->whereIn('status', self::EARNING_STATUSES)
            ->whereNotNull('current_period_end')
            ->whereBetween('current_period_end', [now(), now()->addDays($days)])
            ->sum('amount');
    }

    /** @return Builder<Payment> */
    private function payments(): Builder
    {
        return Payment::query()->withoutGlobalScope(TenantScope::class);
    }

    /** @return Builder<Subscription> */
    private function subscriptions(): Builder
    {
        return Subscription::query()->withoutGlobalScope(TenantScope::class);
    }
}
