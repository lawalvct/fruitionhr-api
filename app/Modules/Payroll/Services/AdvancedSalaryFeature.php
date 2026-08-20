<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Models\EmployeeSalary;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class AdvancedSalaryFeature
{
    public function enabled(): bool
    {
        return app(CurrentTenant::class)->get()?->advancedSalaryFormulasEnabled() ?? false;
    }

    public function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new AccessDeniedHttpException('Advanced salary formulas are disabled. Enable them in Payroll settings first.');
        }
    }

    public function lockAndAssertEnabled(): Tenant
    {
        $tenant = $this->lockTenant();

        $this->assertTenantEnabled($tenant);

        return $tenant;
    }

    public function lockTenant(): Tenant
    {
        return Tenant::query()
            ->whereKey(app(CurrentTenant::class)->id())
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function assertTenantEnabled(Tenant $tenant): void
    {
        if (! $tenant->advancedSalaryFormulasEnabled()) {
            throw new AccessDeniedHttpException('Advanced salary formulas are disabled. Enable them in Payroll settings first.');
        }
    }

    public function activeFormulaSalaryCount(): int
    {
        return EmployeeSalary::query()
            ->where('uses_advanced_formula', true)
            ->where(fn ($query) => $query
                ->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', today()))
            ->count();
    }

    /** @return array{enabled:bool,blocking_employee_salaries:int} */
    public function setEnabled(bool $enabled): array
    {
        return DB::transaction(function () use ($enabled): array {
            $tenantId = app(CurrentTenant::class)->id();
            $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $blocking = $this->activeFormulaSalaryCount();

            if (! $enabled && $blocking > 0) {
                return [
                    'enabled' => $tenant->advancedSalaryFormulasEnabled(),
                    'blocking_employee_salaries' => $blocking,
                ];
            }

            $settings = $tenant->settings ?? [];
            $settings['payroll'] = [
                ...($settings['payroll'] ?? []),
                'advanced_salary_formulas_enabled' => $enabled,
            ];
            $tenant->update(['settings' => $settings]);
            $tenant = $tenant->refresh();
            app(CurrentTenant::class)->set($tenant);
            auth()->user()?->setRelation('tenant', $tenant);

            return ['enabled' => $enabled, 'blocking_employee_salaries' => $blocking];
        });
    }
}
