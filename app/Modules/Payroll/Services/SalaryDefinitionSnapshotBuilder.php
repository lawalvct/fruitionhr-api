<?php

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Formula\SalaryFormulaEngine;
use App\Modules\Payroll\Models\EmployeeSalaryComponentOverride;
use App\Modules\Payroll\Models\SalaryComponent;
use App\Modules\Payroll\Models\SalaryFormulaRevision;
use App\Modules\Payroll\Models\SalaryStructure;
use Illuminate\Validation\ValidationException;

class SalaryDefinitionSnapshotBuilder
{
    public function __construct(private readonly SalaryFormulaEngine $engine) {}

    /**
     * @param  list<array<string, mixed>>  $overrides
     * @return array{snapshot:array,uses_advanced_formula:bool,component_overrides:list<array<string,mixed>>}
     */
    public function build(?int $structureId, array $overrides): array
    {
        $effective = [];

        if ($structureId !== null) {
            $structure = SalaryStructure::query()
                ->whereKey($structureId)
                ->lockForUpdate()
                ->firstOrFail();
            $structure->load(['components.component', 'components.formulaRevision']);

            foreach ($structure->components as $line) {
                if ($line->component === null || $line->component->isReservedBasicSalaryComponent()) {
                    continue;
                }

                $this->assertValidFormulaRevision($line->component, $line->formulaRevision);
                $effective[$line->salary_component_id] = [
                    'component' => $line->component,
                    'amount' => $line->amount,
                    'percent' => $line->percent,
                    'formula_revision' => $line->formulaRevision,
                    'source' => 'structure',
                ];
            }
        }

        $componentIds = collect($overrides)
            ->pluck('salary_component_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $components = SalaryComponent::query()
            ->with('publishedFormulaRevision')
            ->whereIn('id', $componentIds)
            ->get()
            ->keyBy('id');
        $normalizedOverrides = [];

        foreach ($overrides as $override) {
            $componentId = (int) $override['salary_component_id'];
            /** @var SalaryComponent|null $component */
            $component = $components->get($componentId);
            if ($component === null || ! $component->is_active) {
                throw ValidationException::withMessages([
                    'component_overrides' => "Salary component {$componentId} is inactive or unavailable.",
                ]);
            }

            $mode = $override['mode'];
            if ($mode === EmployeeSalaryComponentOverride::MODE_EXCLUDED) {
                unset($effective[$componentId]);
                $normalizedOverrides[] = [
                    'salary_component_id' => $componentId,
                    'formula_revision_id' => null,
                    'mode' => $mode,
                    'amount' => null,
                    'percent' => null,
                ];

                continue;
            }

            $revision = $component->calc_type === SalaryComponent::CALC_FORMULA
                ? ($effective[$componentId]['formula_revision'] ?? $component->publishedFormulaRevision)
                : null;
            $this->assertValidFormulaRevision($component, $revision);

            $effective[$componentId] = [
                'component' => $component,
                'amount' => $override['amount'] ?? null,
                'percent' => $override['percent'] ?? null,
                'formula_revision' => $revision,
                'source' => 'employee_override',
            ];
            $normalizedOverrides[] = [
                'salary_component_id' => $componentId,
                'formula_revision_id' => $revision?->id,
                'mode' => $mode,
                'amount' => $override['amount'] ?? null,
                'percent' => $override['percent'] ?? null,
            ];
        }

        foreach ($effective as $componentId => $line) {
            /** @var SalaryComponent $component */
            $component = $line['component'];
            /** @var SalaryFormulaRevision|null $revision */
            $revision = $line['formula_revision'];
            if ($component->calc_type !== SalaryComponent::CALC_FORMULA
                || $line['amount'] !== null) {
                continue;
            }

            foreach ($this->engine->dependencies($revision->definition) as $dependencyId) {
                if (! array_key_exists($dependencyId, $effective)) {
                    throw ValidationException::withMessages([
                        'component_overrides' => "Formula component {$component->name} requires salary component {$dependencyId} in this employee's effective assignment.",
                    ]);
                }
            }
        }

        $snapshotComponents = collect($effective)
            ->map(function (array $line): array {
                /** @var SalaryComponent $component */
                $component = $line['component'];
                /** @var SalaryFormulaRevision|null $revision */
                $revision = $line['formula_revision'];

                return [
                    'salary_component_id' => $component->id,
                    'name' => $component->name,
                    'code' => $component->code,
                    'type' => $component->type,
                    'calc_type' => $component->calc_type,
                    'component_percent' => $component->percent,
                    'amount' => $line['amount'],
                    'percent' => $line['percent'],
                    'is_taxable' => (bool) $component->is_taxable,
                    'is_pensionable' => (bool) $component->is_pensionable,
                    'source' => $line['source'],
                    'formula_revision' => $revision === null ? null : [
                        'id' => $revision->id,
                        'version' => $revision->version,
                        'definition' => $revision->definition,
                        'summary' => $revision->summary,
                        'checksum' => $revision->checksum,
                        'published_at' => $revision->published_at?->toISOString(),
                    ],
                ];
            })
            ->values()
            ->all();

        $usesFormula = collect($snapshotComponents)
            ->contains(fn (array $line): bool => $line['formula_revision'] !== null);

        return [
            'snapshot' => [
                'schema_version' => 1,
                'components' => $snapshotComponents,
            ],
            'uses_advanced_formula' => $usesFormula,
            'component_overrides' => $normalizedOverrides,
        ];
    }

    private function assertValidFormulaRevision(
        SalaryComponent $component,
        ?SalaryFormulaRevision $revision,
    ): void {
        if ($component->calc_type !== SalaryComponent::CALC_FORMULA) {
            if ($revision !== null) {
                throw ValidationException::withMessages([
                    'components' => "Non-formula component {$component->name} has an invalid formula revision.",
                ]);
            }

            return;
        }

        if ($revision === null
            || ! $revision->isPublished()
            || $revision->salary_component_id !== $component->id) {
            throw ValidationException::withMessages([
                'components' => "Formula component {$component->name} does not have a valid published revision.",
            ]);
        }
    }
}
