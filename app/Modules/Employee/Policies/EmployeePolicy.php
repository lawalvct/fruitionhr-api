<?php

namespace App\Modules\Employee\Policies;

use App\Models\User;
use App\Modules\Employee\Models\Employee;
use App\Support\Authorization\Permissions;

class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::EMPLOYEES_VIEW);
    }

    public function view(User $user, Employee $employee): bool
    {
        return $user->can(Permissions::EMPLOYEES_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(Permissions::EMPLOYEES_CREATE);
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->can(Permissions::EMPLOYEES_UPDATE);
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $user->can(Permissions::EMPLOYEES_DELETE);
    }
}
