<?php

namespace App\Core\Workflow\Console;

use App\Core\Workflow\WorkflowProvisioner;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Console\Command;

/**
 * Provisions default approval workflows for every existing tenant. Idempotent
 * — safe to run after adding new default workflow definitions so tenants
 * created before the change get them too.
 */
class BackfillWorkflowsCommand extends Command
{
    protected $signature = 'workflows:backfill';

    protected $description = 'Provision default approval workflows for all existing tenants';

    public function handle(WorkflowProvisioner $provisioner, CurrentTenant $current): int
    {
        Tenant::query()->each(function (Tenant $tenant) use ($provisioner, $current): void {
            $current->set($tenant);
            $provisioner->provision($tenant);
            $this->line("  <info>✓</info> {$tenant->id} {$tenant->name}");
        });

        $current->forget();
        $this->info('Default workflows provisioned for all tenants.');

        return self::SUCCESS;
    }
}
