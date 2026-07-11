<?php

namespace App\Modules\Payroll\Support;

use App\Modules\Payroll\Models\StatutoryRule;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;

/**
 * Seeds default statutory rules (PAYE/Pension/NHF/NSITF) for a tenant.
 * Idempotent — skips types that already have an active rule.
 */
class StatutoryProvisioner
{
    public function provision(Tenant $tenant): void
    {
        $current = app(CurrentTenant::class);
        $previous = $current->get();
        $current->set($tenant);

        try {
            foreach (StatutoryDefaults::all() as $default) {
                $exists = StatutoryRule::query()
                    ->where('type', $default['type'])
                    ->where('is_active', true)
                    ->exists();

                if ($exists) {
                    continue;
                }

                StatutoryRule::query()->create([
                    'type' => $default['type'],
                    'config' => $default['config'],
                    'effective_from' => StatutoryDefaults::EFFECTIVE_FROM,
                    'is_active' => true,
                ]);
            }
        } finally {
            $previous !== null ? $current->set($previous) : $current->forget();
        }
    }
}
