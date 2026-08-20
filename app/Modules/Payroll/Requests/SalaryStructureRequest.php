<?php

namespace App\Modules\Payroll\Requests;

use App\Modules\Payroll\Formula\SalaryFormulaEngine;
use App\Modules\Payroll\Models\EmployeeSalary;
use App\Modules\Payroll\Models\SalaryComponent;
use App\Modules\Payroll\Models\SalaryStructureComponent;
use App\Modules\Payroll\Services\AdvancedSalaryFeature;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SalaryStructureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can(Permissions::EMPLOYEES_MANAGE_SALARY) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'components' => ['sometimes', 'array'],
            'components.*.salary_component_id' => [
                'required', 'integer', 'distinct',
                Rule::exists('salary_components', 'id')
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'components.*.amount' => ['nullable', 'integer', 'min:0'], // kobo
            'components.*.percent' => ['nullable', 'integer', 'min:0', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $componentIds = collect($this->input('components', []))
                ->pluck('salary_component_id')
                ->filter(fn ($id) => is_numeric($id))
                ->map(fn ($id) => (int) $id)
                ->unique();

            $components = SalaryComponent::query()
                ->with('publishedFormulaRevision')
                ->whereIn('id', $componentIds)
                ->get()
                ->keyBy('id');

            $hasBasicSalary = $components
                ->contains(fn (SalaryComponent $component) => $component->isReservedBasicSalaryComponent());

            if ($hasBasicSalary) {
                $validator->errors()->add(
                    'components',
                    'Basic Salary cannot be included in a salary structure; enter it per employee in Compensation.',
                );
            }

            foreach ($this->input('components', []) as $index => $line) {
                $componentId = filter_var($line['salary_component_id'] ?? null, FILTER_VALIDATE_INT);
                /** @var SalaryComponent|null $component */
                $component = $componentId === false ? null : $components->get((int) $componentId);
                if ($component?->calc_type !== SalaryComponent::CALC_FORMULA) {
                    if (($line['amount'] ?? null) !== null && ($line['percent'] ?? null) !== null) {
                        $validator->errors()->add(
                            "components.{$index}",
                            'Choose either a fixed amount or a percentage, not both.',
                        );
                    }

                    continue;
                }

                if (! ($this->user()?->can(Permissions::PAYROLL_FORMULAS_MANAGE) ?? false)) {
                    $validator->errors()->add(
                        "components.{$index}.salary_component_id",
                        'You do not have permission to use formula salary components.',
                    );
                }

                if (! app(AdvancedSalaryFeature::class)->enabled()) {
                    $validator->errors()->add(
                        "components.{$index}.salary_component_id",
                        'Enable advanced salary formulas in Payroll settings before using this component.',
                    );
                }

                if ($component->publishedFormulaRevision === null) {
                    $validator->errors()->add(
                        "components.{$index}.salary_component_id",
                        'Publish this component formula before adding it to a salary structure.',
                    );
                }

                if ($component->publishedFormulaRevision !== null) {
                    foreach (app(SalaryFormulaEngine::class)->dependencies(
                        $component->publishedFormulaRevision->definition,
                    ) as $dependencyId) {
                        if (! $componentIds->contains($dependencyId)) {
                            $validator->errors()->add(
                                "components.{$index}.salary_component_id",
                                "This formula requires salary component {$dependencyId} in the same structure.",
                            );
                        }
                    }
                }

                if (($line['amount'] ?? null) !== null || ($line['percent'] ?? null) !== null) {
                    $validator->errors()->add(
                        "components.{$index}",
                        'Formula components cannot be replaced with a fixed amount or percentage in a structure.',
                    );
                }
            }

            $structure = $this->route('salaryStructure');
            if ($structure !== null
                && $this->has('components')
                && EmployeeSalary::query()
                    ->where('salary_structure_id', $structure->id)
                    ->whereNull('definition_snapshot')
                    ->exists()
                && $this->financialLinesChanged($structure->id)) {
                $validator->errors()->add(
                    'components',
                    'This structure is used by legacy salary records without immutable snapshots. Create a new structure and reassign employees before changing its component amounts, percentages, or membership.',
                );
            }
        });
    }

    private function financialLinesChanged(int $structureId): bool
    {
        $existing = SalaryStructureComponent::query()
            ->where('salary_structure_id', $structureId)
            ->get(['salary_component_id', 'amount', 'percent'])
            ->map(fn ($line): array => [
                'salary_component_id' => (int) $line->salary_component_id,
                'amount' => $line->amount === null ? null : (int) $line->amount,
                'percent' => $line->percent === null ? null : (int) $line->percent,
            ])
            ->sortBy('salary_component_id')
            ->values()
            ->all();

        $incoming = collect($this->input('components', []))
            ->map(fn (array $line): array => [
                'salary_component_id' => (int) ($line['salary_component_id'] ?? 0),
                'amount' => isset($line['amount']) ? (int) $line['amount'] : null,
                'percent' => isset($line['percent']) ? (int) $line['percent'] : null,
            ])
            ->sortBy('salary_component_id')
            ->values()
            ->all();

        return $existing !== $incoming;
    }
}
