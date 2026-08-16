<?php

namespace App\Modules\Access\Services;

use App\Models\User;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AccessControlService
{
    /** @return Collection<int, Role> */
    public function roles(): Collection
    {
        $roles = Role::query()
            ->where('tenant_id', $this->tenantId())
            ->with('permissions:id,name')
            ->orderByRaw("CASE WHEN name = 'owner' THEN 0 WHEN name = 'hr_admin' THEN 1 WHEN name = 'manager' THEN 2 WHEN name = 'employee' THEN 3 ELSE 4 END")
            ->orderBy('name')
            ->get();

        $counts = DB::table(config('permission.table_names.model_has_roles'))
            ->where(config('permission.column_names.team_foreign_key'), $this->tenantId())
            ->where('model_type', User::class)
            ->selectRaw('role_id, COUNT(*) as aggregate')
            ->groupBy('role_id')
            ->pluck('aggregate', 'role_id');

        return $roles->each(fn (Role $role) => $role->setAttribute(
            'users_count',
            (int) ($counts[$role->id] ?? 0),
        ));
    }

    /** @return Collection<int, User> */
    public function users(): Collection
    {
        return User::query()
            ->where('tenant_id', $this->tenantId())
            ->with([
                'roles' => fn ($query) => $query->where('roles.tenant_id', $this->tenantId()),
                'employee:id,user_id,first_name,middle_name,last_name,employee_number',
            ])
            ->orderBy('name')
            ->get();
    }

    /** @return list<array{module: string, label: string, permissions: list<array{name: string, label: string, action: string}>}> */
    public function permissionGroups(): array
    {
        return collect(Permissions::all())
            ->groupBy(fn (string $permission) => Str::before($permission, '.'))
            ->map(function (Collection $permissions, string $module): array {
                return [
                    'module' => $module,
                    'label' => $this->moduleLabel($module),
                    'permissions' => $permissions->map(function (string $permission) use ($module): array {
                        $parts = explode('.', $permission);
                        array_shift($parts);
                        $action = (string) array_pop($parts);
                        $subject = $parts === [] ? $module : implode(' ', $parts);

                        return [
                            'name' => $permission,
                            'label' => (string) Str::of($action)->replace('_', ' ')->headline().' '.Str::headline($subject),
                            'action' => $action,
                        ];
                    })->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    public function createRole(array $data): Role
    {
        return DB::transaction(function () use ($data): Role {
            $name = $this->normalizedName($data['name']);
            $this->ensureRoleNameAvailable($name);

            $role = Role::query()->create([
                'tenant_id' => $this->tenantId(),
                'name' => $name,
                'guard_name' => 'web',
            ]);

            $role->syncPermissions($this->permissions($data['permissions']));

            return $this->freshRole($role);
        });
    }

    public function updateRole(int $roleId, array $data): Role
    {
        return DB::transaction(function () use ($roleId, $data): Role {
            $role = $this->role($roleId);

            if ($role->name === 'owner') {
                throw ValidationException::withMessages([
                    'role' => 'The owner role is protected and cannot be changed.',
                ]);
            }

            $name = $this->normalizedName($data['name']);

            if ($this->isSystemRole($role->name) && $name !== $role->name) {
                throw ValidationException::withMessages([
                    'name' => 'Built-in roles cannot be renamed because core workflows depend on them.',
                ]);
            }

            $this->ensureRoleNameAvailable($name, $role->id);
            $role->forceFill(['name' => $name])->save();
            $role->syncPermissions($this->permissions($data['permissions']));

            return $this->freshRole($role);
        });
    }

    public function deleteRole(int $roleId): void
    {
        DB::transaction(function () use ($roleId): void {
            $role = $this->role($roleId);

            if ($this->isSystemRole($role->name)) {
                throw ValidationException::withMessages([
                    'role' => 'Built-in roles cannot be deleted.',
                ]);
            }

            if ($this->roleUserCount($role->id) > 0) {
                throw ValidationException::withMessages([
                    'role' => 'Reassign the users holding this role before deleting it.',
                ]);
            }

            $role->delete();
        });
    }

    public function syncUserRoles(int $userId, array $roleIds, User $actor): User
    {
        return DB::transaction(function () use ($userId, $roleIds, $actor): User {
            $user = User::query()
                ->where('tenant_id', $this->tenantId())
                ->findOrFail($userId);

            if ($user->is($actor)) {
                throw ValidationException::withMessages([
                    'user' => 'You cannot change your own roles. Ask another owner to update your access.',
                ]);
            }

            $roles = Role::query()
                ->where('tenant_id', $this->tenantId())
                ->whereIn('id', $roleIds)
                ->get();

            if ($roles->count() !== count(array_unique($roleIds))) {
                throw ValidationException::withMessages([
                    'role_ids' => 'One or more selected roles do not belong to this company.',
                ]);
            }

            $removesOwner = $user->hasRole('owner') && ! $roles->contains('name', 'owner');

            if ($removesOwner && $this->ownerCount() <= 1) {
                throw ValidationException::withMessages([
                    'role_ids' => 'Every company must keep at least one owner.',
                ]);
            }

            $user->syncRoles($roles);

            return $user->refresh()->load([
                'roles' => fn ($query) => $query->where('roles.tenant_id', $this->tenantId()),
                'employee:id,user_id,first_name,middle_name,last_name,employee_number',
            ]);
        });
    }

    private function role(int $roleId): Role
    {
        return Role::query()
            ->where('tenant_id', $this->tenantId())
            ->findOrFail($roleId);
    }

    private function freshRole(Role $role): Role
    {
        $role = $role->refresh()->load('permissions:id,name');
        $role->setAttribute('users_count', $this->roleUserCount($role->id));

        return $role;
    }

    private function roleUserCount(int $roleId): int
    {
        return DB::table(config('permission.table_names.model_has_roles'))
            ->where(config('permission.column_names.team_foreign_key'), $this->tenantId())
            ->where('role_id', $roleId)
            ->where('model_type', User::class)
            ->count();
    }

    /** @param list<string> $names */
    private function permissions(array $names): Collection
    {
        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $names)
            ->get();

        if ($permissions->count() !== count(array_unique($names))) {
            throw ValidationException::withMessages([
                'permissions' => 'One or more permissions are not available.',
            ]);
        }

        return $permissions;
    }

    private function ensureRoleNameAvailable(string $name, ?int $exceptId = null): void
    {
        $query = Role::query()
            ->where('tenant_id', $this->tenantId())
            ->where('guard_name', 'web')
            ->where('name', $name);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => 'A role with this name already exists in your company.',
            ]);
        }
    }

    private function normalizedName(string $name): string
    {
        $normalized = Str::snake(trim($name));

        if ($normalized === '') {
            throw ValidationException::withMessages(['name' => 'Enter a role name.']);
        }

        return $normalized;
    }

    private function isSystemRole(string $name): bool
    {
        return array_key_exists($name, Permissions::defaultRoles());
    }

    private function ownerCount(): int
    {
        return User::query()
            ->where('tenant_id', $this->tenantId())
            ->whereHas('roles', fn ($query) => $query
                ->where('roles.name', 'owner')
                ->where('roles.tenant_id', $this->tenantId()))
            ->count();
    }

    private function moduleLabel(string $module): string
    {
        return match ($module) {
            'ess' => 'Employee self-service',
            'mss' => 'Manager self-service',
            default => (string) Str::of($module)->replace('_', ' ')->headline(),
        };
    }

    private function tenantId(): int
    {
        $tenantId = app(CurrentTenant::class)->id();

        abort_if($tenantId === null, 403, 'A company context is required.');

        return $tenantId;
    }
}
