<?php

namespace App\Modules\Tenancy\Services;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlatformTenantService
{
    public const TRIAL_ENDING_SOON_DAYS = 30;

    /** @return array<string, int> */
    public function metrics(): array
    {
        return [
            'tenants_total' => Tenant::query()->count(),
            'tenants_active' => Tenant::query()->where('status', Tenant::STATUS_ACTIVE)->count(),
            'tenants_suspended' => Tenant::query()->where('status', Tenant::STATUS_SUSPENDED)->count(),
            'tenants_cancelled' => Tenant::query()->where('status', Tenant::STATUS_CANCELLED)->count(),
            'tenant_users_total' => User::query()
                ->whereNotNull('tenant_id')
                ->whereHas('tenant')
                ->count(),
            'trials_ending_soon' => Tenant::query()
                ->where('status', Tenant::STATUS_ACTIVE)
                ->whereBetween('trial_ends_at', [now(), now()->addDays(self::TRIAL_ENDING_SOON_DAYS)])
                ->count(),
            'onboarding_completed' => Tenant::query()
                ->where('onboarding_status', Tenant::ONBOARDING_COMPLETED)
                ->count(),
            'onboarding_pending' => Tenant::query()
                ->whereIn('onboarding_status', [
                    Tenant::ONBOARDING_NOT_STARTED,
                    Tenant::ONBOARDING_IN_PROGRESS,
                ])
                ->count(),
        ];
    }

    /**
     * @return list<array{period: string, label: string, count: int}>
     */
    public function companyGrowth(int $months = 6): array
    {
        $firstMonth = now()->startOfMonth()->subMonths($months - 1);
        $lastMonth = now()->endOfMonth();

        $counts = Tenant::query()
            ->whereBetween('created_at', [$firstMonth, $lastMonth])
            ->get(['created_at'])
            ->countBy(fn (Tenant $tenant): string => $tenant->created_at->format('Y-m'));

        return collect(range(0, $months - 1))
            ->map(function (int $offset) use ($firstMonth, $counts): array {
                $month = $firstMonth->copy()->addMonths($offset);
                $period = $month->format('Y-m');

                return [
                    'period' => $period,
                    'label' => $month->format('M'),
                    'count' => (int) ($counts[$period] ?? 0),
                ];
            })
            ->all();
    }

    /**
     * @return list<array{status: string, label: string, count: int}>
     */
    public function statusDistribution(): array
    {
        $counts = Tenant::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return collect([
            Tenant::STATUS_ACTIVE => 'Active',
            Tenant::STATUS_SUSPENDED => 'Suspended',
            Tenant::STATUS_CANCELLED => 'Cancelled',
        ])->map(fn (string $label, string $status): array => [
            'status' => $status,
            'label' => $label,
            'count' => (int) ($counts[$status] ?? 0),
        ])->values()->all();
    }

    /**
     * @return list<array{status: string, label: string, count: int}>
     */
    public function onboardingDistribution(): array
    {
        $counts = Tenant::query()
            ->selectRaw('onboarding_status, COUNT(*) as aggregate')
            ->groupBy('onboarding_status')
            ->pluck('aggregate', 'onboarding_status');

        return collect([
            Tenant::ONBOARDING_NOT_STARTED => 'Not started',
            Tenant::ONBOARDING_IN_PROGRESS => 'In progress',
            Tenant::ONBOARDING_COMPLETED => 'Completed',
            Tenant::ONBOARDING_SKIPPED => 'Skipped',
        ])->map(fn (string $label, string $status): array => [
            'status' => $status,
            'label' => $label,
            'count' => (int) ($counts[$status] ?? 0),
        ])->values()->all();
    }

    /** @return Collection<int, Tenant> */
    public function recent(int $limit = 6): Collection
    {
        return Tenant::query()
            ->withCount('users')
            ->latest('created_at')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Tenant>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Tenant::query()->withCount('users');

        if (($filters['search'] ?? null) !== null) {
            $search = trim((string) $filters['search']);
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }

        if (($filters['onboarding_status'] ?? null) !== null) {
            $query->where('onboarding_status', $filters['onboarding_status']);
        }

        [$column, $direction] = $this->sort((string) ($filters['sort'] ?? '-created_at'));

        return $query
            ->orderBy($column, $direction)
            ->orderBy('id', $direction)
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->appends($filters);
    }

    public function find(int $tenantId): Tenant
    {
        return Tenant::query()->withCount('users')->findOrFail($tenantId);
    }

    /**
     * @param  array{name?: string, email?: string, phone?: ?string, trial_ends_at?: ?string}  $data
     * @return array{tenant: Tenant, before: array<string, mixed>, after: array<string, mixed>}
     */
    public function update(int $tenantId, array $data): array
    {
        return DB::transaction(function () use ($tenantId, $data): array {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($tenantId);
            $before = $this->snapshot($tenant);

            $tenant->fill($data);
            $tenant->save();

            $tenant = $tenant->refresh()->loadCount('users');

            return [
                'tenant' => $tenant,
                'before' => $before,
                'after' => $this->snapshot($tenant),
            ];
        });
    }

    /**
     * @return array{tenant: Tenant, before: array<string, mixed>, after: array<string, mixed>}
     */
    public function suspend(int $tenantId): array
    {
        return $this->changeStatus($tenantId, Tenant::STATUS_SUSPENDED);
    }

    /**
     * @return array{tenant: Tenant, before: array<string, mixed>, after: array<string, mixed>}
     */
    public function activate(int $tenantId): array
    {
        return $this->changeStatus($tenantId, Tenant::STATUS_ACTIVE);
    }

    /**
     * @return array{tenant: Tenant, before: array<string, mixed>, after: array<string, mixed>}
     */
    private function changeStatus(int $tenantId, string $status): array
    {
        return DB::transaction(function () use ($tenantId, $status): array {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($tenantId);

            if ($tenant->status === $status) {
                throw ValidationException::withMessages([
                    'status' => "This company is already {$status}.",
                ]);
            }

            if ($status === Tenant::STATUS_SUSPENDED && $tenant->status !== Tenant::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'status' => 'Only an active company can be suspended.',
                ]);
            }

            if ($status === Tenant::STATUS_ACTIVE && $tenant->status !== Tenant::STATUS_SUSPENDED) {
                throw ValidationException::withMessages([
                    'status' => 'Only a suspended company can be activated.',
                ]);
            }

            $before = $this->snapshot($tenant);
            $tenant->forceFill(['status' => $status])->save();
            $tenant = $tenant->refresh()->loadCount('users');

            return [
                'tenant' => $tenant,
                'before' => $before,
                'after' => $this->snapshot($tenant),
            ];
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(Tenant $tenant): array
    {
        return [
            'name' => $tenant->name,
            'email' => $tenant->email,
            'phone' => $tenant->phone,
            'status' => $tenant->status,
            'trial_ends_at' => $tenant->trial_ends_at?->toIso8601String(),
        ];
    }

    /** @return array{string, 'asc'|'desc'} */
    private function sort(string $sort): array
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        return [$column, $direction];
    }
}
