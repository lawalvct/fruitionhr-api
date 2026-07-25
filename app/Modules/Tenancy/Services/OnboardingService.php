<?php

namespace App\Modules\Tenancy\Services;

use App\Models\User;
use App\Modules\Performance\Services\PerformanceDefaultsProvisioner;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class OnboardingService
{
    public function __construct(
        private readonly StarterDataProvisioner $starterData,
        private readonly PerformanceDefaultsProvisioner $performanceDefaults,
    ) {}

    public function save(User $owner, array $input): Tenant
    {
        $tenant = $owner->tenant;
        $data = array_merge($tenant->onboarding_data ?? [], Arr::except($input, ['step']));

        $isCompleted = $tenant->onboarding_status === Tenant::ONBOARDING_COMPLETED;

        $tenant->update([
            'name' => $data['company_name'] ?? $tenant->name,
            'phone' => $data['phone'] ?? $tenant->phone,
            'onboarding_status' => $isCompleted ? Tenant::ONBOARDING_COMPLETED : Tenant::ONBOARDING_IN_PROGRESS,
            'onboarding_step' => $isCompleted ? 3 : $input['step'],
            'onboarding_data' => $data,
        ]);

        return $tenant->refresh();
    }

    public function finish(User $owner, bool $skipped): Tenant
    {
        return DB::transaction(function () use ($owner, $skipped): Tenant {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($owner->tenant_id);

            if (in_array($tenant->onboarding_status, [
                Tenant::ONBOARDING_COMPLETED,
                Tenant::ONBOARDING_SKIPPED,
            ], true)) {
                return $tenant;
            }

            $this->starterData->provision($owner, $tenant->onboarding_data ?? []);

            // Optional: seed the sample KPI library and appraisal templates
            // when the company chose default performance data during setup.
            if (($tenant->onboarding_data['seed_performance_defaults'] ?? false) === true) {
                $this->performanceDefaults->provision($owner);
            }

            $tenant->update([
                'onboarding_status' => $skipped
                    ? Tenant::ONBOARDING_SKIPPED
                    : Tenant::ONBOARDING_COMPLETED,
                'onboarding_step' => 3,
                'onboarding_completed_at' => $skipped ? null : now(),
                'onboarding_skipped_at' => $skipped ? now() : null,
            ]);

            return $tenant->refresh();
        });
    }
}
