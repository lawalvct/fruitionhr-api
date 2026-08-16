<?php

namespace App\Modules\Admin\Services;

use App\Modules\Recruitment\Models\Application;
use App\Modules\Recruitment\Models\Vacancy;
use App\Support\Tenancy\TenantScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only platform view of recruitment across every tenant.
 *
 * TenantScope fails closed, so each query and every eager-loaded relation on a
 * tenant-owned model must drop it explicitly. That removal is deliberately
 * confined to this class — the super-admin middleware on the routes above is
 * what makes it safe, and nothing here writes.
 */
class PlatformRecruitmentService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Vacancy>
     */
    public function paginateVacancies(array $filters): LengthAwarePaginator
    {
        $query = Vacancy::query()
            ->withoutGlobalScope(TenantScope::class)
            ->with(['tenant:id,name,slug', 'employmentType' => $this->unscoped()])
            ->withCount(['applications' => $this->unscoped()]);

        if (($filters['search'] ?? null) !== null) {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%")
                    ->orWhereHas('tenant', fn (Builder $t) => $t->where('name', 'like', "%{$search}%"));
            });
        }

        if (($filters['status'] ?? null) !== null) {
            $query->where('status', $filters['status']);
        }

        if (($filters['tenant_id'] ?? null) !== null) {
            $query->where('tenant_id', (int) $filters['tenant_id']);
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->appends($filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Application>
     */
    public function paginateApplications(array $filters): LengthAwarePaginator
    {
        $query = Application::query()
            ->withoutGlobalScope(TenantScope::class)
            ->with([
                'tenant:id,name,slug',
                'applicant' => $this->unscoped(),
                'vacancy' => $this->unscoped(),
            ]);

        if (($filters['search'] ?? null) !== null) {
            $search = trim((string) $filters['search']);
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->whereHas('applicant', fn (Builder $a) => $a
                        ->withoutGlobalScope(TenantScope::class)
                        ->where(function (Builder $inner) use ($search): void {
                            $inner
                                ->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        }))
                    ->orWhereHas('vacancy', fn (Builder $v) => $v
                        ->withoutGlobalScope(TenantScope::class)
                        ->where('title', 'like', "%{$search}%"));
            });
        }

        if (($filters['stage'] ?? null) !== null) {
            $query->where('stage', $filters['stage']);
        }

        if (($filters['vacancy_id'] ?? null) !== null) {
            $query->where('vacancy_id', (int) $filters['vacancy_id']);
        }

        if (($filters['tenant_id'] ?? null) !== null) {
            $query->where('tenant_id', (int) $filters['tenant_id']);
        }

        return $query
            ->orderByDesc('applied_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->appends($filters);
    }

    public function findVacancy(int $vacancyId): Vacancy
    {
        return Vacancy::query()
            ->withoutGlobalScope(TenantScope::class)
            ->with(['tenant:id,name,slug', 'employmentType' => $this->unscoped()])
            ->withCount(['applications' => $this->unscoped()])
            ->findOrFail($vacancyId);
    }

    /**
     * Headline numbers for the console. Counted across every tenant.
     *
     * @return array<string, int>
     */
    public function summary(): array
    {
        $vacancies = Vacancy::query()->withoutGlobalScope(TenantScope::class);
        $applications = Application::query()->withoutGlobalScope(TenantScope::class);

        return [
            'total_vacancies' => (clone $vacancies)->count(),
            'open_vacancies' => (clone $vacancies)->where('status', Vacancy::STATUS_OPEN)->count(),
            'total_applications' => (clone $applications)->count(),
            'hired' => (clone $applications)->where('stage', 'hired')->count(),
            'hiring_companies' => (clone $vacancies)->distinct()->count('tenant_id'),
        ];
    }

    /** Eager-load/count closure that drops the tenant scope. */
    private function unscoped(): callable
    {
        return fn ($query) => $query->withoutGlobalScope(TenantScope::class);
    }
}
