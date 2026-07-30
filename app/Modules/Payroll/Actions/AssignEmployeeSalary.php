<?php

namespace App\Modules\Payroll\Actions;

use App\Modules\Employee\Models\Employee;
use App\Modules\Payroll\Models\EmployeeSalary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Assigns a new salary to an employee, closing the previous current record so
 * history is preserved (mirrors employment records).
 */
class AssignEmployeeSalary
{
    /**
     * @param  array{basic_salary:int, salary_structure_id:?int, effective_from:string, component_overrides?:array, change_type?:string, change_reason?:?string}  $input
     */
    public function execute(Employee $employee, array $input, int $userId): EmployeeSalary
    {
        return DB::transaction(function () use ($employee, $input, $userId): EmployeeSalary {
            $previousEnd = Carbon::createFromFormat('Y-m-d', $input['effective_from'])
                ->subDay()
                ->toDateString();

            EmployeeSalary::query()
                ->where('employee_id', $employee->id)
                ->where('is_current', true)
                ->update(['is_current' => false, 'effective_to' => $previousEnd]);

            $salary = EmployeeSalary::query()->create([
                'employee_id' => $employee->id,
                'salary_structure_id' => $input['salary_structure_id'] ?? null,
                'basic_salary' => $input['basic_salary'],
                'effective_from' => $input['effective_from'],
                'is_current' => true,
                'change_type' => $input['change_type'] ?? EmployeeSalary::CHANGE_COMPENSATION,
                'change_reason' => $input['change_reason'] ?? null,
                'created_by' => $userId,
            ]);

            $salary->componentOverrides()->createMany($input['component_overrides'] ?? []);

            return $salary;
        });
    }
}
