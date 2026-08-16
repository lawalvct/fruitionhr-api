<?php

namespace App\Modules\Admin\Services;

use App\Modules\Auth\Services\PlatformAdministratorService;
use App\Modules\Tenancy\Services\PlatformTenantService;

class PlatformDashboardService
{
    public function __construct(
        private readonly PlatformTenantService $tenants,
        private readonly PlatformAdministratorService $administrators,
        private readonly PlatformActivityService $activity,
    ) {}

    /** @return array<string, mixed> */
    public function dashboard(): array
    {
        $tenantMetrics = $this->tenants->metrics();

        return [
            'generated_at' => now()->toIso8601String(),
            'metrics' => [
                'tenants_total' => $tenantMetrics['tenants_total'],
                'tenants_active' => $tenantMetrics['tenants_active'],
                'tenants_suspended' => $tenantMetrics['tenants_suspended'],
                'tenants_cancelled' => $tenantMetrics['tenants_cancelled'],
                'tenant_users_total' => $tenantMetrics['tenant_users_total'],
                'administrators_total' => $this->administrators->count(),
                'trials_ending_soon' => $tenantMetrics['trials_ending_soon'],
                'onboarding_completed' => $tenantMetrics['onboarding_completed'],
                'onboarding_pending' => $tenantMetrics['onboarding_pending'],
            ],
            'company_growth' => $this->tenants->companyGrowth(),
            'status_distribution' => $this->tenants->statusDistribution(),
            'onboarding_distribution' => $this->tenants->onboardingDistribution(),
            'recent_tenants' => $this->tenants->recent(),
            'recent_activity' => $this->activity->recent(),
        ];
    }
}
