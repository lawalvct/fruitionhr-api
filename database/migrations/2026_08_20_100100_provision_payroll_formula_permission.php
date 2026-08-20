<?php

use App\Modules\Tenancy\Models\Tenant;
use App\Support\Authorization\Permissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::findOrCreate(Permissions::PAYROLL_FORMULAS_MANAGE, 'web');
        $previousTeamId = getPermissionsTeamId();

        try {
            Tenant::query()->each(function (Tenant $tenant) use ($permission): void {
                setPermissionsTeamId($tenant->id);

                Role::query()
                    ->with('permissions')
                    ->where('tenant_id', $tenant->id)
                    ->whereIn('name', ['owner', 'hr_admin'])
                    ->get()
                    ->each(fn (Role $role) => $role->givePermissionTo($permission));
            });
        } finally {
            setPermissionsTeamId($previousTeamId);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        // Permission provisioning is intentionally not reversed.
    }
};
