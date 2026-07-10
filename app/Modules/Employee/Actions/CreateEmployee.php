<?php

namespace App\Modules\Employee\Actions;

use App\Modules\Employee\Models\Employee;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;

class CreateEmployee
{
    public function execute(array $employeeData, ?array $assignmentData = null, ?int $createdBy = null): Employee
    {
        return DB::transaction(function () use ($employeeData, $assignmentData, $createdBy): Employee {
            $employee = Employee::create([
                ...$employeeData,
                'employee_number' => $employeeData['employee_number'] ?? $this->nextEmployeeNumber(),
                'created_by' => $createdBy,
            ]);

            if ($assignmentData !== null) {
                app(AssignEmployee::class)->execute($employee, $assignmentData, $createdBy);
            }

            return $employee->load($this->relations());
        });
    }

    private function nextEmployeeNumber(): string
    {
        $tenantId = app(CurrentTenant::class)->id();

        $lastNumber = Employee::query()
            ->where('tenant_id', $tenantId)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('employee_number');

        $next = 1;

        if (is_string($lastNumber) && preg_match('/EMP-(\d+)/', $lastNumber, $matches)) {
            $next = ((int) $matches[1]) + 1;
        }

        return 'EMP-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function relations(): array
    {
        return [
            'currentAssignment.branch',
            'currentAssignment.department',
            'currentAssignment.position',
            'currentAssignment.jobGrade',
            'currentAssignment.employmentType',
            'contacts',
            'bankAccounts',
            'statutoryDetails',
        ];
    }
}
