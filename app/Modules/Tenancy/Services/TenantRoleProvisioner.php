<?php

namespace App\Modules\Tenancy\Services;

use App\Modules\Tenancy\Models\Tenant;
use App\Support\Authorization\Permissions;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the default role set for a tenant (Spatie teams mode: role rows
 * carry tenant_id). Idempotent — safe to re-run when new defaults ship.
 */
class TenantRoleProvisioner
{
    public function provision(Tenant $tenant): void
    {
        // Permissions are global rows; ensure the catalogue exists.
        foreach (Permissions::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId($tenant->id);

        try {
            foreach (Permissions::defaultRoles() as $roleName => $permissions) {
                $role = Role::findOrCreate($roleName, 'web');
                $role->syncPermissions($permissions);
            }
        } finally {
            setPermissionsTeamId($previousTeamId);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
}
