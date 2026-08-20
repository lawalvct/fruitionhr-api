<?php

namespace App\Modules\Payroll\Controllers;

use App\Modules\Payroll\Formula\SalaryFormulaDraftConflictException;
use App\Modules\Payroll\Formula\SalaryFormulaEngine;
use App\Modules\Payroll\Formula\SalaryFormulaException;
use App\Modules\Payroll\Models\SalaryComponent;
use App\Modules\Payroll\Services\AdvancedSalaryFeature;
use App\Modules\Payroll\Services\SalaryFormulaService;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SalaryFormulaController extends Controller
{
    public function catalog(Request $request, AdvancedSalaryFeature $feature): JsonResponse
    {
        $this->authorizeAndGate($request, $feature);

        $components = SalaryComponent::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->reject(fn (SalaryComponent $component) => $component->isReservedBasicSalaryComponent());

        $componentItems = $components->map(fn (SalaryComponent $component): array => [
            'label' => $component->name,
            'value_type' => 'money',
            'token' => ['type' => 'component', 'component_id' => $component->id],
            'available' => $component->calc_type !== SalaryComponent::CALC_PERCENT_OF_GROSS,
            'unavailable_reason' => $component->calc_type === SalaryComponent::CALC_PERCENT_OF_GROSS
                ? 'Percent-of-gross components cannot be formula dependencies.'
                : null,
            'component' => [
                'id' => $component->id,
                'code' => $component->code,
                'type' => $component->type,
                'calc_type' => $component->calc_type,
            ],
        ])->groupBy(fn (array $item) => $item['component']['type']);

        $groups = collect([
            ['key' => 'fixed_amount', 'label' => 'Fixed amount', 'items' => [[
                'label' => 'Fixed amount', 'value_type' => 'money',
                'token' => ['type' => 'amount', 'value_kobo' => 0], 'available' => true,
            ]]],
            ['key' => 'basic', 'label' => 'Basic salary', 'items' => [[
                'label' => 'Basic salary', 'value_type' => 'money',
                'token' => ['type' => 'basic'], 'available' => true,
            ]]],
            ['key' => 'percentage', 'label' => 'Percentage', 'items' => [[
                'label' => 'Percentage', 'value_type' => 'scalar',
                'token' => ['type' => 'percentage', 'basis_points' => 1000], 'available' => true,
            ]]],
        ]);

        foreach ([
            SalaryComponent::TYPE_EARNING => 'Earnings',
            SalaryComponent::TYPE_DEDUCTION => 'Deductions',
            SalaryComponent::TYPE_EMPLOYER_CONTRIBUTOR => 'Company contributions',
            SalaryComponent::TYPE_FRINGE_BENEFIT => 'Fringe benefits',
        ] as $key => $label) {
            $groups->push(['key' => $key, 'label' => $label, 'items' => ($componentItems[$key] ?? collect())->values()->all()]);
        }

        return response()->json(['data' => [
            'schema_version' => SalaryFormulaEngine::SCHEMA_VERSION,
            'limits' => [
                'max_rules' => SalaryFormulaEngine::MAX_RULES,
                'max_tokens_per_calculation' => SalaryFormulaEngine::MAX_TOKENS,
                'max_parenthesis_depth' => SalaryFormulaEngine::MAX_DEPTH,
                'max_amount_kobo' => SalaryFormulaEngine::MAX_AMOUNT_KOBO,
                'max_percentage_basis_points' => SalaryFormulaEngine::MAX_PERCENTAGE_BASIS_POINTS,
            ],
            'operators' => [
                ['value' => '+', 'label' => 'Add'], ['value' => '-', 'label' => 'Subtract'],
                ['value' => '*', 'label' => 'Multiply'], ['value' => '/', 'label' => 'Divide'],
            ],
            'comparators' => [
                ['value' => 'eq', 'label' => 'Equals'], ['value' => 'neq', 'label' => 'Does not equal'],
                ['value' => 'gt', 'label' => 'Greater than'], ['value' => 'gte', 'label' => 'Greater than or equal to'],
                ['value' => 'lt', 'label' => 'Less than'], ['value' => 'lte', 'label' => 'Less than or equal to'],
            ],
            'groups' => $groups->all(),
        ]]);
    }

    public function show(Request $request, SalaryComponent $salaryComponent, AdvancedSalaryFeature $feature, SalaryFormulaService $service): JsonResponse
    {
        $this->authorizeAndGate($request, $feature);

        return response()->json(['data' => $this->translate(
            fn () => $service->payload($salaryComponent, $feature)
        )]);
    }

    public function saveDraft(Request $request, SalaryComponent $salaryComponent, AdvancedSalaryFeature $feature, SalaryFormulaService $service): JsonResponse
    {
        $this->authorizeAndGate($request, $feature);
        $data = $request->validate([
            'definition' => ['required', 'array'],
            'expected_draft_id' => ['present', 'nullable', 'integer', 'min:1'],
            'expected_checksum' => ['present', 'nullable', 'string', 'size:64'],
        ]);
        if (($data['expected_draft_id'] === null) !== ($data['expected_checksum'] === null)) {
            throw ValidationException::withMessages([
                'expected_checksum' => 'Expected draft id and checksum must both be provided, or both be null.',
            ]);
        }

        try {
            $this->translate(fn () => $service->saveDraft(
                $salaryComponent,
                $data['definition'],
                $request->user(),
                $data['expected_draft_id'],
                $data['expected_checksum'],
            ));
        } catch (SalaryFormulaDraftConflictException $exception) {
            return $this->draftConflict($exception, $salaryComponent, $feature, $service);
        }

        return response()->json(['data' => $service->payload($salaryComponent->refresh(), $feature)]);
    }

    public function evaluate(Request $request, SalaryComponent $salaryComponent, AdvancedSalaryFeature $feature, SalaryFormulaService $service): JsonResponse
    {
        $this->authorizeAndGate($request, $feature);
        $tenantId = app(CurrentTenant::class)->id();
        $data = $request->validate([
            'definition' => ['sometimes', 'array'],
            'basic_salary' => ['required', 'integer', 'min:0', 'max:'.SalaryFormulaEngine::MAX_AMOUNT_KOBO],
            'component_values' => ['sometimes', 'array', 'max:'.SalaryFormulaEngine::MAX_DEPENDENCIES],
            'component_values.*.salary_component_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('salary_components', 'id')->where('tenant_id', $tenantId)->whereNull('deleted_at'),
            ],
            'component_values.*.amount' => ['required', 'integer', 'min:0', 'max:'.SalaryFormulaEngine::MAX_AMOUNT_KOBO],
        ]);

        $values = collect($data['component_values'] ?? [])->mapWithKeys(
            fn (array $value) => [(int) $value['salary_component_id'] => (int) $value['amount']]
        )->all();

        $result = $this->translate(fn () => $service->evaluate(
            $salaryComponent,
            $data['definition'] ?? null,
            (int) $data['basic_salary'],
            $values,
        ));

        $dependencies = SalaryComponent::query()
            ->whereIn('id', app(SalaryFormulaEngine::class)->dependencies($result['definition']))
            ->orderBy('name')
            ->get()
            ->map(fn (SalaryComponent $component) => [
                'id' => $component->id,
                'name' => $component->name,
                'code' => $component->code,
                'amount' => $values[$component->id],
            ])->values()->all();

        return response()->json(['data' => [
            'result_kobo' => $result['result_kobo'],
            'matched_rule_index' => $result['matched_rule_index'],
            'dependencies' => $dependencies,
            'inputs' => ['basic_salary' => (int) $data['basic_salary']],
            'summary' => app(SalaryFormulaEngine::class)->summary($result['definition']),
        ]]);
    }

    public function publish(Request $request, SalaryComponent $salaryComponent, AdvancedSalaryFeature $feature, SalaryFormulaService $service): JsonResponse
    {
        $this->authorizeAndGate($request, $feature);
        $data = $request->validate([
            'expected_draft_id' => ['required', 'integer', 'min:1'],
            'expected_checksum' => ['required', 'string', 'size:64'],
        ]);
        try {
            $this->translate(fn () => $service->publish(
                $salaryComponent,
                $request->user(),
                (int) $data['expected_draft_id'],
                $data['expected_checksum'],
            ));
        } catch (SalaryFormulaDraftConflictException $exception) {
            return $this->draftConflict($exception, $salaryComponent, $feature, $service);
        }

        return response()->json(['data' => $service->payload($salaryComponent->refresh(), $feature)]);
    }

    private function authorizeAndGate(Request $request, AdvancedSalaryFeature $feature): void
    {
        abort_unless($request->user()->can(Permissions::PAYROLL_FORMULAS_MANAGE), 403);
        $feature->assertEnabled();
    }

    private function translate(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (SalaryFormulaDraftConflictException $exception) {
            throw $exception;
        } catch (SalaryFormulaException $exception) {
            throw ValidationException::withMessages(['definition' => $exception->getMessage()]);
        }
    }

    private function draftConflict(
        SalaryFormulaDraftConflictException $exception,
        SalaryComponent $component,
        AdvancedSalaryFeature $feature,
        SalaryFormulaService $service,
    ): JsonResponse {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->errorCode,
            'data' => $service->payload($component->refresh(), $feature),
        ], 409);
    }
}
