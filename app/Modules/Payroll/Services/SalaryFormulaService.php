<?php

namespace App\Modules\Payroll\Services;

use App\Models\User;
use App\Modules\Payroll\Formula\SalaryFormulaDraftConflictException;
use App\Modules\Payroll\Formula\SalaryFormulaEngine;
use App\Modules\Payroll\Formula\SalaryFormulaException;
use App\Modules\Payroll\Models\SalaryComponent;
use App\Modules\Payroll\Models\SalaryFormulaRevision;
use Illuminate\Support\Facades\DB;

class SalaryFormulaService
{
    public function __construct(
        private readonly SalaryFormulaEngine $engine,
        private readonly AdvancedSalaryFeature $feature,
    ) {}

    public function saveDraft(
        SalaryComponent $component,
        array $definition,
        User $user,
        ?int $expectedDraftId,
        ?string $expectedChecksum,
    ): SalaryFormulaRevision {
        $this->assertFormulaComponent($component);

        return DB::transaction(function () use (
            $component,
            $definition,
            $user,
            $expectedDraftId,
            $expectedChecksum,
        ): SalaryFormulaRevision {
            $this->feature->lockAndAssertEnabled();
            $locked = SalaryComponent::query()->whereKey($component->id)->lockForUpdate()->firstOrFail();
            $draft = SalaryFormulaRevision::query()
                ->where('salary_component_id', $locked->id)
                ->where('status', SalaryFormulaRevision::STATUS_DRAFT)
                ->lockForUpdate()
                ->first();
            $this->assertExpectedDraft($draft, $expectedDraftId, $expectedChecksum);

            $normalized = $this->engine->normalize($definition, $locked->id);
            $this->assertAcyclic($locked->id, $normalized);

            $attributes = [
                'definition' => $normalized,
                'summary' => $this->engine->summary($normalized),
                'checksum' => $this->engine->checksum($normalized),
            ];

            if ($draft !== null) {
                $draft->update($attributes);

                return $draft->refresh();
            }

            $nextVersion = ((int) SalaryFormulaRevision::query()
                ->where('salary_component_id', $locked->id)
                ->max('version')) + 1;

            return SalaryFormulaRevision::query()->create([
                'salary_component_id' => $locked->id,
                'version' => $nextVersion,
                'status' => SalaryFormulaRevision::STATUS_DRAFT,
                ...$attributes,
                'created_by' => $user->id,
            ]);
        });
    }

    /**
     * @param  array<int, int>  $componentValues
     * @return array{result_kobo:int,matched_rule_index:int,definition:array}
     */
    public function evaluate(SalaryComponent $component, ?array $definition, int $basicSalary, array $componentValues): array
    {
        $this->assertFormulaComponent($component);

        if ($definition === null) {
            $revision = $component->draftFormulaRevision()->first()
                ?? $component->publishedFormulaRevision()->first();

            if ($revision === null) {
                throw new SalaryFormulaException('Save a formula draft before evaluating it.');
            }

            $definition = $revision->definition;
        }

        $normalized = $this->engine->normalize($definition, $component->id);
        $this->assertAcyclic($component->id, $normalized);

        return [
            ...$this->engine->evaluate($normalized, $basicSalary, $componentValues),
            'definition' => $normalized,
        ];
    }

    public function publish(
        SalaryComponent $component,
        User $user,
        int $expectedDraftId,
        string $expectedChecksum,
    ): SalaryFormulaRevision {
        $this->assertFormulaComponent($component);

        return DB::transaction(function () use (
            $component,
            $user,
            $expectedDraftId,
            $expectedChecksum,
        ): SalaryFormulaRevision {
            $this->feature->lockAndAssertEnabled();
            $locked = SalaryComponent::query()->whereKey($component->id)->lockForUpdate()->firstOrFail();
            $draft = SalaryFormulaRevision::query()
                ->where('salary_component_id', $locked->id)
                ->where('status', SalaryFormulaRevision::STATUS_DRAFT)
                ->lockForUpdate()
                ->first();

            $this->assertExpectedDraft($draft, $expectedDraftId, $expectedChecksum);

            if ($draft === null) {
                throw new SalaryFormulaException('There is no formula draft to publish.');
            }

            $normalized = $this->engine->normalize($draft->definition, $locked->id);
            $this->assertAcyclic($locked->id, $normalized);
            $this->assertDependenciesPublishable($normalized);

            $draft->update([
                'status' => SalaryFormulaRevision::STATUS_PUBLISHED,
                'definition' => $normalized,
                'summary' => $this->engine->summary($normalized),
                'checksum' => $this->engine->checksum($normalized),
                'published_by' => $user->id,
                'published_at' => now(),
            ]);

            return $draft->refresh();
        });
    }

    /** @return array<string,mixed> */
    public function payload(SalaryComponent $component, AdvancedSalaryFeature $feature): array
    {
        $this->assertFormulaComponent($component);
        $component->loadMissing(['draftFormulaRevision', 'publishedFormulaRevision']);

        return [
            'component' => [
                'id' => $component->id,
                'name' => $component->name,
                'code' => $component->code,
                'type' => $component->type,
                'calc_type' => $component->calc_type,
            ],
            'advanced_salary_formulas_enabled' => $feature->enabled(),
            'draft' => $this->revisionPayload($component->draftFormulaRevision),
            'published' => $this->revisionPayload($component->publishedFormulaRevision),
        ];
    }

    /** @return array<string,mixed>|null */
    public function revisionPayload(?SalaryFormulaRevision $revision): ?array
    {
        if ($revision === null) {
            return null;
        }

        return [
            'id' => $revision->id,
            'version' => $revision->version,
            'status' => $revision->status,
            'definition' => $revision->definition,
            'summary' => $revision->summary,
            'checksum' => $revision->checksum,
            'dependency_ids' => $this->engine->dependencies($revision->definition),
            'created_at' => $revision->created_at?->toISOString(),
            'updated_at' => $revision->updated_at?->toISOString(),
            'published_at' => $revision->published_at?->toISOString(),
        ];
    }

    private function assertFormulaComponent(SalaryComponent $component): void
    {
        if ($component->calc_type !== SalaryComponent::CALC_FORMULA) {
            throw new SalaryFormulaException('Only formula salary components have formula definitions.');
        }
    }

    private function assertAcyclic(int $candidateComponentId, array $candidateDefinition): void
    {
        $latest = SalaryFormulaRevision::query()
            ->where('status', SalaryFormulaRevision::STATUS_PUBLISHED)
            ->orderByDesc('version')
            ->get()
            ->unique('salary_component_id');

        $graph = [];
        foreach ($latest as $revision) {
            $graph[$revision->salary_component_id] = $this->engine->dependencies($revision->definition);
        }
        $graph[$candidateComponentId] = $this->engine->dependencies($candidateDefinition);

        $visiting = [];
        $visited = [];
        $visit = function (int $node) use (&$visit, &$visiting, &$visited, $graph): void {
            if (isset($visiting[$node])) {
                throw new SalaryFormulaException('The formula introduces a circular component dependency.', 'FORMULA_CYCLE');
            }
            if (isset($visited[$node])) {
                return;
            }

            $visiting[$node] = true;
            foreach ($graph[$node] ?? [] as $dependency) {
                $visit($dependency);
            }
            unset($visiting[$node]);
            $visited[$node] = true;
        };

        $visit($candidateComponentId);
    }

    private function assertDependenciesPublishable(array $definition): void
    {
        $dependencyIds = $this->engine->dependencies($definition);
        if ($dependencyIds === []) {
            return;
        }

        $components = SalaryComponent::query()
            ->with('publishedFormulaRevision')
            ->whereIn('id', $dependencyIds)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        foreach ($dependencyIds as $dependencyId) {
            /** @var SalaryComponent|null $dependency */
            $dependency = $components->get($dependencyId);
            if ($dependency === null) {
                throw new SalaryFormulaException(
                    "Referenced salary component {$dependencyId} is inactive or no longer available.",
                    'FORMULA_MISSING_COMPONENT',
                );
            }

            if ($dependency->calc_type === SalaryComponent::CALC_FORMULA
                && $dependency->publishedFormulaRevision === null) {
                throw new SalaryFormulaException(
                    "Formula component {$dependency->name} must have a published revision before it can be referenced.",
                    'FORMULA_UNPUBLISHED_DEPENDENCY',
                );
            }
        }
    }

    private function assertExpectedDraft(
        ?SalaryFormulaRevision $draft,
        ?int $expectedDraftId,
        ?string $expectedChecksum,
    ): void {
        if ($draft === null) {
            if ($expectedDraftId !== null || $expectedChecksum !== null) {
                throw new SalaryFormulaDraftConflictException(null);
            }

            return;
        }

        if ($expectedDraftId === null
            || $expectedChecksum === null
            || $draft->id !== $expectedDraftId
            || ! hash_equals($draft->checksum, $expectedChecksum)) {
            throw new SalaryFormulaDraftConflictException($draft);
        }
    }
}
