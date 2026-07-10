<?php

namespace App\Core\Workflow;

use App\Core\Workflow\Models\WorkflowDefinition;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;

/**
 * Seeds default approval workflows for a new tenant. Idempotent.
 * Tenants can customise these later via the workflow settings UI.
 */
class WorkflowProvisioner
{
    /**
     * module => [ [step_name, approver_role], ... ]
     */
    private const DEFAULTS = [
        'leave' => [
            ['Manager approval', 'manager'],
            ['HR approval', 'hr_admin'],
        ],
        'profile_update' => [
            ['HR approval', 'hr_admin'],
        ],
        'payroll' => [
            ['HR approval', 'hr_admin'],
            ['Owner approval', 'owner'],
        ],
    ];

    public function provision(Tenant $tenant): void
    {
        $current = app(CurrentTenant::class);
        $previous = $current->get();
        $current->set($tenant);

        try {
            foreach (self::DEFAULTS as $module => $steps) {
                $definition = WorkflowDefinition::query()->firstOrCreate(
                    ['module' => $module],
                    ['name' => ucfirst(str_replace('_', ' ', $module)).' approval', 'is_active' => true],
                );

                if ($definition->steps()->exists()) {
                    continue;
                }

                foreach ($steps as $index => [$stepName, $role]) {
                    $definition->steps()->create([
                        'step_order' => $index + 1,
                        'step_name' => $stepName,
                        'approver_role' => $role,
                    ]);
                }
            }
        } finally {
            $previous !== null ? $current->set($previous) : $current->forget();
        }
    }
}
