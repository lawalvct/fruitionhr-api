<?php

namespace App\Modules\Payroll\Actions;

use App\Modules\Employee\Models\Employee;
use App\Modules\Payroll\Models\EmployeeSalary;
use Illuminate\Support\Facades\DB;

/**
 * Assigns a new salary to an employee, closing the previous current record so
 * history is preserved (mirrors employment records).
 */
class AssignEmployeeSalary
{
    /**
     * @param  array{basic_salary:int, salary_structure_id:?int, effective_from:string}  $input
     */
    public function execute(Employee $employee, array $input, int $userId): EmployeeSalary
    {
        return DB::transaction(function () use ($employee, $input, $userId): EmployeeSalary {
            EmployeeSalary::query()
                ->where('employee_id', $employee->id)
                ->where('is_current', true)
                ->update(['is_current' => false, 'effective_to' => $input['effective_from']]);

            return EmployeeSalary::query()->create([
                'employee_id' => $employee->id,
                'salary_structure_id' => $input['salary_structure_id'] ?? null,
                'basic_salary' => $input['basic_salary'],
                'effective_from' => $input['effective_from'],
                'is_current' => true,
                'created_by' => $userId,
            ]);
        });
    }
}
