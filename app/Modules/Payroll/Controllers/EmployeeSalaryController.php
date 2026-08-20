<?php

namespace App\Modules\Payroll\Controllers;

use App\Modules\Employee\Models\Employee;
use App\Modules\Payroll\Actions\AssignEmployeeSalary;
use App\Modules\Payroll\Formula\SalaryFormulaException;
use App\Modules\Payroll\Models\EmployeeSalary;
use App\Modules\Payroll\Models\EmployeeSalaryComponentOverride;
use App\Modules\Payroll\Models\SalaryComponent;
use App\Modules\Payroll\Support\SalaryResolver;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeSalaryController extends Controller
{
    public function __construct(private readonly SalaryResolver $resolver) {}

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
            'basic_salary' => ['required', 'integer', 'min:1'], // kobo; canonical monthly basic
            'salary_structure_id' => [
                'nullable', 'integer',
                Rule::exists('salary_structures', 'id')
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'component_overrides' => ['sometimes', 'array'],
            'component_overrides.*.salary_component_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('salary_components', 'id')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at'),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $component = SalaryComponent::query()->find($value);
                    if ($component?->isReservedBasicSalaryComponent()) {
                        $fail('Basic Salary cannot be used as an employee component override.');
                    }
                },
            ],
            'component_overrides.*.mode' => ['required', Rule::in([
                EmployeeSalaryComponentOverride::MODE_OVERRIDE,
                EmployeeSalaryComponentOverride::MODE_ADDITIONAL,
                EmployeeSalaryComponentOverride::MODE_EXCLUDED,
            ])],
            'component_overrides.*.amount' => ['nullable', 'integer', 'min:0'],
            'component_overrides.*.percent' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $this->validateComponentOverrides($data['component_overrides'] ?? []);

        $this->validateRevisionDate($employee, $data['effective_from']);
        $data['change_type'] = EmployeeSalary::query()->where('employee_id', $employee->id)->exists()
            ? EmployeeSalary::CHANGE_COMPENSATION
            : EmployeeSalary::CHANGE_ASSIGNMENT;

        $salary = $this->assign($action, $employee, $data, $request->user()->id);
        $salary->load(['structure.components.component', 'componentOverrides.component']);

        return response()->json(['data' => $this->present($salary)], 201);
    }

    public function history(Request $request, Employee $employee)
    {
        abort_unless($request->user()->can(Permissions::EMPLOYEES_VIEW_SALARY), 403);

        $history = EmployeeSalary::query()
            ->where('employee_id', $employee->id)
            ->with(['structure.components.component', 'componentOverrides.component'])
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get()
            ->map(fn (EmployeeSalary $salary) => $this->present($salary));

        return response()->json(['data' => $history]);
    }

    public function increase(Request $request, Employee $employee, AssignEmployeeSalary $action)
    {
        abort_unless($request->user()->can(Permissions::EMPLOYEES_MANAGE_SALARY), 403);

        $latest = EmployeeSalary::query()
            ->where('employee_id', $employee->id)
            ->with('componentOverrides')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            throw ValidationException::withMessages(['basic_salary' => 'Assign a salary before recording an increase.']);
        }

        $data = $request->validate([
            'basic_salary' => ['required', 'integer', 'min:1'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'change_reason' => ['required', 'string', 'max:500'],
        ]);

        if ($data['basic_salary'] <= $latest->basic_salary) {
            throw ValidationException::withMessages(['basic_salary' => 'The new basic salary must be greater than the previous basic salary.']);
        }

        $this->validateRevisionDate($employee, $data['effective_from']);

        $salary = $this->assign($action, $employee, [
            ...$data,
            'salary_structure_id' => $latest->salary_structure_id,
            'change_type' => EmployeeSalary::CHANGE_BASIC_INCREASE,
            'component_overrides' => $latest->componentOverrides->map(fn (EmployeeSalaryComponentOverride $override) => [
                'salary_component_id' => $override->salary_component_id,
                'formula_revision_id' => $override->formula_revision_id,
                'mode' => $override->mode,
                'amount' => $override->amount,
                'percent' => $override->percent,
            ])->all(),
            'definition_snapshot' => $latest->definition_snapshot,
            'uses_advanced_formula' => $latest->uses_advanced_formula,
            'previous_salary_id' => $latest->id,
        ], $request->user()->id);
        $salary->load(['structure.components.component', 'componentOverrides.component']);

        return response()->json(['data' => $this->present($salary)], 201);
    }

    private function currentSalary(Employee $employee): ?EmployeeSalary
    {
        return EmployeeSalary::query()
            ->where('employee_id', $employee->id)
            ->effectiveOn(today())
            ->orderByDesc('effective_from')
            ->with(['structure.components.component', 'componentOverrides.component'])
            ->first();
    }

    private function present(EmployeeSalary $salary): array
    {
        $structureComponents = $salary->structure?->components ?? collect();
        $breakdown = $this->resolver->resolve(
            $salary->basic_salary,
            $structureComponents,
            $salary->componentOverrides,
            $salary->definition_snapshot,
        );

        return [
            'id' => $salary->id,
            'basic_salary' => $salary->basic_salary,
            'effective_from' => $salary->effective_from->toDateString(),
            'effective_to' => $salary->effective_to?->toDateString(),
            'change_type' => $salary->change_type,
            'change_reason' => $salary->change_reason,
            'uses_advanced_formula' => $salary->uses_advanced_formula,
            'status' => $this->status($salary),
            'structure' => $salary->structure ? [
                'id' => $salary->structure->id,
                'name' => $salary->structure->name,
            ] : null,
            'component_overrides' => $salary->componentOverrides->map(fn (EmployeeSalaryComponentOverride $override) => [
                'salary_component_id' => $override->salary_component_id,
                'formula_revision_id' => $override->formula_revision_id,
                'mode' => $override->mode,
                'amount' => $override->amount,
                'percent' => $override->percent,
                'component_name' => $override->component?->name,
                'component_code' => $override->component?->code,
            ])->values()->all(),
            'breakdown' => [
                'basic' => $breakdown->basic,
                'earnings' => $breakdown->earnings,
                'deductions' => $breakdown->deductions,
                'employer_contributions' => $breakdown->employerContributions,
                'fringe_benefits' => $breakdown->fringeBenefits,
                'gross' => $breakdown->gross(),
                'taxable_pay' => $breakdown->taxablePay(),
                'pensionable_pay' => $breakdown->pensionablePay(),
            ],
        ];
    }

    /** @param list<array<string,mixed>> $overrides */
    private function validateComponentOverrides(array $overrides): void
    {
        $components = SalaryComponent::query()
            ->with('publishedFormulaRevision')
            ->whereIn('id', collect($overrides)->pluck('salary_component_id'))
            ->get()
            ->keyBy('id');

        foreach ($overrides as $index => $override) {
            /** @var SalaryComponent|null $component */
            $component = $components->get((int) $override['salary_component_id']);
            if ($component === null) {
                continue;
            }

            if ($component->calc_type === SalaryComponent::CALC_FORMULA) {
                if (! in_array($override['mode'], [
                    EmployeeSalaryComponentOverride::MODE_OVERRIDE,
                    EmployeeSalaryComponentOverride::MODE_ADDITIONAL,
                    EmployeeSalaryComponentOverride::MODE_EXCLUDED,
                ], true)) {
                    throw ValidationException::withMessages([
                        "component_overrides.{$index}.mode" => 'This formula component override mode is invalid.',
                    ]);
                }

                if ($override['mode'] === EmployeeSalaryComponentOverride::MODE_OVERRIDE
                    && ($override['amount'] ?? null) === null) {
                    throw ValidationException::withMessages([
                        "component_overrides.{$index}.amount" => 'Enter the fixed amount that replaces this employee formula.',
                    ]);
                }

                if ($override['mode'] !== EmployeeSalaryComponentOverride::MODE_OVERRIDE
                    && (($override['amount'] ?? null) !== null || ($override['percent'] ?? null) !== null)) {
                    throw ValidationException::withMessages([
                        "component_overrides.{$index}" => 'Only a formula override may carry a fixed replacement amount.',
                    ]);
                }

                if (($override['percent'] ?? null) !== null) {
                    throw ValidationException::withMessages([
                        "component_overrides.{$index}.percent" => 'Formula components cannot be replaced with a percentage.',
                    ]);
                }

                if (in_array($override['mode'], [
                    EmployeeSalaryComponentOverride::MODE_ADDITIONAL,
                    EmployeeSalaryComponentOverride::MODE_OVERRIDE,
                ], true)
                    && $component->publishedFormulaRevision === null) {
                    throw ValidationException::withMessages([
                        "component_overrides.{$index}.salary_component_id" => 'Publish this component formula before assigning it to an employee.',
                    ]);
                }

                continue;
            }

            if ($override['mode'] !== EmployeeSalaryComponentOverride::MODE_EXCLUDED
                && ($override['amount'] ?? null) === null
                && ($override['percent'] ?? null) === null) {
                throw ValidationException::withMessages([
                    "component_overrides.{$index}.amount" => 'Enter an amount or percentage for this component override.',
                ]);
            }

            if (($override['amount'] ?? null) !== null && ($override['percent'] ?? null) !== null) {
                throw ValidationException::withMessages([
                    "component_overrides.{$index}" => 'Choose either a fixed amount or a percentage, not both.',
                ]);
            }
        }
    }

    private function validateRevisionDate(Employee $employee, string $effectiveFrom): void
    {
        $date = Carbon::createFromFormat('Y-m-d', $effectiveFrom);
        if ($date->day !== 1) {
            throw ValidationException::withMessages(['effective_from' => 'Salary changes must take effect on the first day of a payroll month.']);
        }

        $latest = EmployeeSalary::query()
            ->where('employee_id', $employee->id)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();

        if ($latest && $date->lessThanOrEqualTo($latest->effective_from)) {
            throw ValidationException::withMessages(['effective_from' => 'The effective date must be after the latest salary revision.']);
        }
    }

    private function assign(
        AssignEmployeeSalary $action,
        Employee $employee,
        array $input,
        int $userId,
    ): EmployeeSalary {
        try {
            return $action->execute($employee, $input, $userId);
        } catch (SalaryFormulaException $exception) {
            throw ValidationException::withMessages([
                'salary_formula' => "[{$exception->errorCode}] {$exception->getMessage()}",
            ]);
        }
    }

    private function status(EmployeeSalary $salary): string
    {
        $today = today();
        if ($salary->effective_from->isAfter($today)) {
            return 'scheduled';
        }

        return $salary->effective_to && $salary->effective_to->isBefore($today) ? 'past' : 'current';
    }
}
