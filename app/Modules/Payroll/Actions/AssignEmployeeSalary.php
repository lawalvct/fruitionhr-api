<?php

namespace App\Modules\Payroll\Actions;

use App\Modules\Employee\Models\Employee;
use App\Modules\Payroll\Models\EmployeeSalary;
use App\Modules\Payroll\Services\AdvancedSalaryFeature;
use App\Modules\Payroll\Services\SalaryDefinitionSnapshotBuilder;
use App\Modules\Payroll\Support\SalarySnapshotResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Assigns a new salary to an employee, closing the previous current record so
 * history is preserved (mirrors employment records).
 */
class AssignEmployeeSalary
{
    public function __construct(
        private readonly SalaryDefinitionSnapshotBuilder $snapshotBuilder,
        private readonly AdvancedSalaryFeature $feature,
        private readonly SalarySnapshotResolver $snapshotResolver,
    ) {}

    /**
     * @param  array{basic_salary:int, salary_structure_id:?int, effective_from:string, component_overrides?:array, change_type?:string, change_reason?:?string}  $input
     */
    public function execute(Employee $employee, array $input, int $userId): EmployeeSalary
    {
        return DB::transaction(function () use ($employee, $input, $userId): EmployeeSalary {
            $tenant = $this->feature->lockTenant();
            Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            EmployeeSalary::query()
                ->where('employee_id', $employee->id)
                ->lockForUpdate()
                ->get();

            $latest = EmployeeSalary::query()
                ->where('employee_id', $employee->id)
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->first();
            $effectiveFrom = Carbon::createFromFormat('Y-m-d', $input['effective_from']);

            if ($latest !== null && $effectiveFrom->lessThanOrEqualTo($latest->effective_from)) {
                throw ValidationException::withMessages([
                    'effective_from' => 'The effective date must be after the latest salary revision.',
                ]);
            }

            if (isset($input['previous_salary_id'])
                && (int) $input['previous_salary_id'] !== (int) $latest?->id) {
                throw ValidationException::withMessages([
                    'effective_from' => 'The salary changed while this increase was being saved. Refresh and try again.',
                ]);
            }

            if (isset($input['definition_snapshot'])) {
                $definition = [
                    'snapshot' => $input['definition_snapshot'],
                    'uses_advanced_formula' => (bool) ($input['uses_advanced_formula'] ?? false),
                    'component_overrides' => $input['component_overrides'] ?? [],
                ];
            } else {
                $definition = $this->snapshotBuilder->build(
                    $input['salary_structure_id'] ?? null,
                    $input['component_overrides'] ?? [],
                );
            }

            if ($definition['uses_advanced_formula']) {
                // Runtime of existing snapshots is not gated, but creating a
                // new formula salary requires the locked setting to be on.
                $this->feature->assertTenantEnabled($tenant);
            }

            // Validate and calculate the exact immutable definition before any
            // current row is closed or a new salary is persisted.
            $this->snapshotResolver->resolve((int) $input['basic_salary'], $definition['snapshot']);

            $previousEnd = $effectiveFrom
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
                'uses_advanced_formula' => $definition['uses_advanced_formula'],
                'definition_snapshot' => $definition['snapshot'],
                'effective_from' => $input['effective_from'],
                'is_current' => true,
                'change_type' => $input['change_type'] ?? EmployeeSalary::CHANGE_COMPENSATION,
                'change_reason' => $input['change_reason'] ?? null,
                'created_by' => $userId,
            ]);

            $salary->componentOverrides()->createMany($definition['component_overrides']);

            return $salary;
        });
    }
}
