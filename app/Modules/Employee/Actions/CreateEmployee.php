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

        // Include trashed rows: soft-deleted employees still hold their slot in
        // the (tenant_id, employee_number) unique index. Scan every EMP-#### for
        // the true maximum rather than parsing only the newest row — otherwise a
        // custom/imported number (e.g. "SAMPLE-003") on the latest row resets the
        // sequence back to EMP-0001 and collides with an existing employee.
        $existing = Employee::withTrashed()
            ->where('tenant_id', $tenantId)
            ->lockForUpdate()
            ->pluck('employee_number')
            ->filter(fn ($number): bool => is_string($number));

        $highest = $existing
            ->map(fn (string $number): int => preg_match('/^EMP-(\d+)$/', $number, $matches) ? (int) $matches[1] : 0)
            ->max() ?? 0;

        $used = $existing->flip();
        $next = $highest + 1;

        do {
            $candidate = 'EMP-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
            $next++;
        } while ($used->has($candidate));

        return $candidate;
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
