<?php

namespace App\Console\Commands;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantRoleProvisioner;
use Illuminate\Console\Command;

/**
 * Re-runs TenantRoleProvisioner for every tenant (or one, via --tenant).
 * Adding a permission to Permissions::defaultRoles() only affects tenants
 * provisioned *after* the change — existing tenants' roles keep whatever
 * permission set they had at provisioning time until this is run.
 *
 *   php artisan tenants:sync-roles
 *   php artisan tenants:sync-roles --tenant=42
 */
class SyncTenantRolesCommand extends Command
{
    protected $signature = 'tenants:sync-roles {--tenant= : Sync a single tenant ID instead of all tenants}';

    protected $description = 'Re-sync every tenant role to the current Permissions::defaultRoles() catalogue';

    public function handle(TenantRoleProvisioner $provisioner): int
    {
        $tenants = $this->option('tenant')
            ? Tenant::query()->where('id', $this->option('tenant'))->get()
            : Tenant::query()->get();

        if ($tenants->isEmpty()) {
            $this->error('No matching tenant(s) found.');

            return self::FAILURE;
        }

        foreach ($tenants as $tenant) {
            $provisioner->provision($tenant);
            $this->info("Synced roles for tenant #{$tenant->id} ({$tenant->name}).");
        }

        return self::SUCCESS;
    }
}
