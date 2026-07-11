<?php

namespace App\Modules\Payroll\Controllers;

use App\Modules\Employee\Models\Employee;
use App\Modules\Payroll\Actions\AssignEmployeeSalary;
use App\Modules\Payroll\Models\EmployeeSalary;
use App\Modules\Payroll\Support\SalaryResolver;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class EmployeeSalaryController extends Controller
{
    public function __construct(private readonly SalaryResolver $resolver)
    {
    }

    public function show(Request $request, Employee $employee)
    {
        abort_unless($request->user()->can(Permissions::EMPLOYEES_VIEW_SALARY), 403);

        $salary = $this->currentSalary($employee);

        if ($salary === null) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => $this->present($salary)]);
    }

    public function store(Request $request, Employee $employee, AssignEmployeeSalary $action)
    {
        abort_unless($request->user()->can(Permissions::EMPLOYEES_MANAGE_SALARY), 403);

        $tenantId = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'basic_salary' => ['required', 'integer', 'min:0'], // kobo
            'salary_structure_id' => [
                'nullable', 'integer',
                Rule::exists('salary_structures', 'id')->where('tenant_id', $tenantId),
            ],
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ]);

        $salary = $action->execute($employee, $data, $request->user()->id);

        return response()->json(['data' => $this->present($salary)], 201);
    }

    private function currentSalary(Employee $employee): ?EmployeeSalary
    {
        return EmployeeSalary::query()
            ->where('employee_id', $employee->id)
            ->where('is_current', true)
            ->with('structure.components.component')
            ->first();
    }

    private function present(EmployeeSalary $salary): array
    {
        $structureComponents = $salary->structure?->components ?? collect();
        $breakdown = $this->resolver->resolve($salary->basic_salary, $structureComponents);

        return [
            'id' => $salary->id,
            'basic_salary' => $salary->basic_salary,
            'effective_from' => $salary->effective_from->toDateString(),
            'structure' => $salary->structure ? [
                'id' => $salary->structure->id,
                'name' => $salary->structure->name,
            ] : null,
            'breakdown' => [
                'basic' => $breakdown->basic,
                'earnings' => $breakdown->earnings,
                'deductions' => $breakdown->deductions,
                'gross' => $breakdown->gross(),
                'taxable_pay' => $breakdown->taxablePay(),
                'pensionable_pay' => $breakdown->pensionablePay(),
            ],
        ];
    }
}
