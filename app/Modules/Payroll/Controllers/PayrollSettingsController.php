<?php

namespace App\Modules\Payroll\Controllers;

use App\Modules\Payroll\Services\AdvancedSalaryFeature;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PayrollSettingsController extends Controller
{
    public function show(Request $request, AdvancedSalaryFeature $feature): JsonResponse
    {
        abort_unless(
            $request->user()->can(Permissions::EMPLOYEES_VIEW_SALARY)
                || $request->user()->can(Permissions::PAYROLL_FORMULAS_MANAGE),
            403,
        );

        return response()->json(['data' => $this->data($feature)]);
    }

    public function enable(Request $request, AdvancedSalaryFeature $feature): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PAYROLL_FORMULAS_MANAGE), 403);
        $feature->setEnabled(true);

        return response()->json(['data' => $this->data($feature)]);
    }

    public function disable(Request $request, AdvancedSalaryFeature $feature): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::PAYROLL_FORMULAS_MANAGE), 403);
        $result = $feature->setEnabled(false);

        if ($result['blocking_employee_salaries'] > 0) {
            return response()->json([
                'message' => 'Advanced salary formulas cannot be disabled while current or scheduled salaries use them.',
                'code' => 'ADVANCED_SALARY_FORMULAS_IN_USE',
                'data' => ['blocking_employee_salaries' => $result['blocking_employee_salaries']],
            ], 409);
        }

        return response()->json(['data' => $this->data($feature)]);
    }

    /** @return array{advanced_salary_formulas_enabled:bool,active_formula_salary_count:int} */
    private function data(AdvancedSalaryFeature $feature): array
    {
        return [
            'advanced_salary_formulas_enabled' => $feature->enabled(),
            'active_formula_salary_count' => $feature->activeFormulaSalaryCount(),
        ];
    }
}
