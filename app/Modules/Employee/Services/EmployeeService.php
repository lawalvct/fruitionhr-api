<?php

namespace App\Modules\Employee\Services;

use App\Modules\Employee\Actions\CreateEmployee;
use App\Modules\Employee\Models\Employee;

class EmployeeService
{
    public function __construct(private readonly CreateEmployee $createEmployee) {}

    public function create(array $employeeData, ?array $assignmentData = null, ?int $createdBy = null): Employee
    {
        return $this->createEmployee->execute($employeeData, $assignmentData, $createdBy);
    }
}
