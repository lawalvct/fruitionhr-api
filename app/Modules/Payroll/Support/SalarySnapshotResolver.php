<?php

namespace App\Modules\Payroll\Support;

use App\Modules\Payroll\Formula\SalaryFormulaEngine;
use App\Modules\Payroll\Formula\SalaryFormulaException;
use App\Modules\Payroll\Models\SalaryComponent;

class SalarySnapshotResolver
{
    public function __construct(private readonly SalaryFormulaEngine $engine) {}

    public function resolve(int $basic, array $snapshot): SalaryBreakdown
    {
        if (($snapshot['schema_version'] ?? null) !== 1
            || ! is_array($snapshot['components'] ?? null)
            || ! array_is_list($snapshot['components'])) {
            throw new SalaryFormulaException('The employee salary definition snapshot is invalid.', 'SALARY_SNAPSHOT_INVALID');
        }

        $lines = [];
        $seen = [];
        foreach ($snapshot['components'] as $snapshotLine) {
            if (! is_array($snapshotLine) || ! is_int($snapshotLine['salary_component_id'] ?? null)) {
                throw new SalaryFormulaException('The employee salary definition snapshot contains an invalid component.', 'SALARY_SNAPSHOT_INVALID');
            }

            $componentId = $snapshotLine['salary_component_id'];
            if (isset($seen[$componentId])) {
                throw new SalaryFormulaException('The employee salary definition snapshot contains a duplicate component.', 'SALARY_SNAPSHOT_INVALID');
            }
            $seen[$componentId] = true;

            $component = new SalaryComponent;
            $component->forceFill([
                'id' => $componentId,
                'name' => (string) ($snapshotLine['name'] ?? ''),
                'code' => (string) ($snapshotLine['code'] ?? ''),
                'type' => (string) ($snapshotLine['type'] ?? ''),
                'calc_type' => (string) ($snapshotLine['calc_type'] ?? ''),
                'percent' => $snapshotLine['component_percent'] ?? null,
                'is_taxable' => (bool) ($snapshotLine['is_taxable'] ?? false),
                'is_pensionable' => (bool) ($snapshotLine['is_pensionable'] ?? false),
            ]);

            $lines[] = (object) [
                'amount' => $snapshotLine['amount'] ?? null,
                'percent' => $snapshotLine['percent'] ?? null,
                'component' => $component,
                'formula_revision' => $snapshotLine['formula_revision'] ?? null,
            ];
        }

        [$amounts, $formulaMetadata] = $this->amountsFor($basic, $lines);

        $earnings = [];
        $deductions = [];
        $employerContributions = [];
        $fringeBenefits = [];

        foreach ($lines as $index => $line) {
            $component = $line->component;
            $item = [
                'code' => $component->code,
                'name' => $component->name,
                'amount' => $amounts[$index],
            ];
            if (isset($formulaMetadata[$index])) {
                $item['formula'] = $formulaMetadata[$index];
            }

            if ($component->type === SalaryComponent::TYPE_DEDUCTION) {
                $deductions[] = $item;

                continue;
            }

            if ($component->type === SalaryComponent::TYPE_EMPLOYER_CONTRIBUTOR) {
                $employerContributions[] = $item;

                continue;
            }

            $item['is_taxable'] = (bool) $component->is_taxable;
            $item['is_pensionable'] = (bool) $component->is_pensionable;

            if ($component->type === SalaryComponent::TYPE_FRINGE_BENEFIT) {
                $fringeBenefits[] = $item;

                continue;
            }

            $earnings[] = $item;
        }

        return new SalaryBreakdown($basic, $earnings, $deductions, $employerContributions, $fringeBenefits);
    }

    /**
     * @param  list<object>  $lines
     * @return array{0:array<int,int>,1:array<int,array<string,mixed>>}
     */
    private function amountsFor(int $basic, array $lines): array
    {
        $amounts = [];
        $followsGross = [];
        $formulaLines = [];
        $lineIndexByComponent = [];
        $formulaMetadata = [];

        foreach ($lines as $index => $line) {
            $componentId = (int) $line->component->id;
            $lineIndexByComponent[$componentId] = $index;

            if ($line->component->calc_type === SalaryComponent::CALC_FORMULA
                && $line->amount === null) {
                if (! is_array($line->formula_revision)
                    || ! is_array($line->formula_revision['definition'] ?? null)) {
                    throw new SalaryFormulaException(
                        "Formula component {$line->component->name} has no pinned published definition.",
                        'SALARY_SNAPSHOT_INVALID',
                    );
                }
                $formulaLines[$componentId] = $line;

                continue;
            }

            if ($line->component->calc_type === SalaryComponent::CALC_FORMULA
                && is_array($line->formula_revision)) {
                $formulaMetadata[$index] = [
                    'revision_id' => $line->formula_revision['id'] ?? null,
                    'version' => $line->formula_revision['version'] ?? null,
                    'checksum' => $line->formula_revision['checksum'] ?? null,
                    'summary' => $line->formula_revision['summary'] ?? null,
                    'bypassed_by_override' => true,
                    'inputs' => null,
                    'result_kobo' => (int) $line->amount,
                ];
            }

            $percent = $this->grossPercentFor($line, $line->component);
            if ($percent === null) {
                $amounts[$index] = $this->fixedOrBasicAmount($basic, $line, $line->component);
            } else {
                $followsGross[$index] = $percent;
            }
        }

        $componentValues = [];
        foreach ($amounts as $index => $amount) {
            $componentValues[(int) $lines[$index]->component->id] = $amount;
        }

        foreach ($this->topologicalFormulaOrder($formulaLines) as $componentId) {
            $line = $formulaLines[$componentId];
            $revision = $line->formula_revision;
            $definition = $this->engine->normalize($revision['definition'], $componentId);

            if (isset($revision['checksum'])
                && ! hash_equals((string) $revision['checksum'], $this->engine->checksum($definition))) {
                throw new SalaryFormulaException(
                    "The pinned formula snapshot for {$line->component->name} failed its integrity check.",
                    'SALARY_SNAPSHOT_CHECKSUM_MISMATCH',
                );
            }

            $inputs = [];
            foreach ($this->engine->dependencies($definition) as $dependencyId) {
                if (! array_key_exists($dependencyId, $lineIndexByComponent)) {
                    throw new SalaryFormulaException(
                        "Formula component {$line->component->name} is missing required assigned component {$dependencyId}.",
                        'FORMULA_MISSING_INPUT',
                    );
                }

                if (! array_key_exists($dependencyId, $componentValues)) {
                    throw new SalaryFormulaException(
                        "Formula component {$line->component->name} could not resolve component {$dependencyId}.",
                        'FORMULA_MISSING_INPUT',
                    );
                }

                $inputs[$dependencyId] = $componentValues[$dependencyId];
            }

            $result = $this->engine->evaluate($definition, $basic, $inputs);
            $index = $lineIndexByComponent[$componentId];
            $amounts[$index] = $result['result_kobo'];
            $componentValues[$componentId] = $result['result_kobo'];
            $formulaMetadata[$index] = [
                'revision_id' => $revision['id'] ?? null,
                'version' => $revision['version'] ?? null,
                'checksum' => $revision['checksum'] ?? null,
                'summary' => $revision['summary'] ?? null,
                'matched_rule_index' => $result['matched_rule_index'],
                'inputs' => [
                    'basic_salary' => $basic,
                    'components' => collect($inputs)->map(
                        fn (int $amount, int $id): array => ['salary_component_id' => $id, 'amount' => $amount]
                    )->values()->all(),
                ],
                'result_kobo' => $result['result_kobo'],
            ];
        }

        $earningsBase = $basic;
        foreach ($amounts as $index => $amount) {
            if ($lines[$index]->component->type === SalaryComponent::TYPE_EARNING) {
                $earningsBase += $amount;
            }
        }

        $gross = $earningsBase;
        foreach ($followsGross as $index => $percent) {
            if ($lines[$index]->component->type !== SalaryComponent::TYPE_EARNING) {
                continue;
            }

            $amounts[$index] = $this->percentOf($earningsBase, $percent);
            $gross += $amounts[$index];
        }

        foreach ($followsGross as $index => $percent) {
            if ($lines[$index]->component->type === SalaryComponent::TYPE_EARNING) {
                continue;
            }

            $amounts[$index] = $this->percentOf($gross, $percent);
        }

        ksort($amounts);

        return [$amounts, $formulaMetadata];
    }

    /** @param array<int,object> $formulaLines @return list<int> */
    private function topologicalFormulaOrder(array $formulaLines): array
    {
        $visiting = [];
        $visited = [];
        $order = [];
        $visit = function (int $componentId) use (&$visit, &$visiting, &$visited, &$order, $formulaLines): void {
            if (isset($visiting[$componentId])) {
                throw new SalaryFormulaException('The salary snapshot contains a circular formula dependency.', 'FORMULA_CYCLE');
            }
            if (isset($visited[$componentId])) {
                return;
            }

            $visiting[$componentId] = true;
            foreach ($this->engine->dependencies($formulaLines[$componentId]->formula_revision['definition']) as $dependencyId) {
                if (isset($formulaLines[$dependencyId])) {
                    $visit($dependencyId);
                }
            }
            unset($visiting[$componentId]);
            $visited[$componentId] = true;
            $order[] = $componentId;
        };

        foreach (array_keys($formulaLines) as $componentId) {
            $visit($componentId);
        }

        return $order;
    }

    private function grossPercentFor(object $line, SalaryComponent $component): ?int
    {
        if ($line->amount !== null || $line->percent !== null) {
            return null;
        }

        return $component->calc_type === SalaryComponent::CALC_PERCENT_OF_GROSS && $component->percent !== null
            ? (int) $component->percent
            : null;
    }

    private function fixedOrBasicAmount(int $basic, object $line, SalaryComponent $component): int
    {
        if ($line->amount !== null) {
            return (int) $line->amount;
        }
        if ($line->percent !== null) {
            return $this->percentOf($basic, (int) $line->percent);
        }
        if ($component->calc_type === SalaryComponent::CALC_PERCENT && $component->percent !== null) {
            return $this->percentOf($basic, (int) $component->percent);
        }

        return 0;
    }

    private function percentOf(int $base, int $percent): int
    {
        return (int) round($base * $percent / 100);
    }
}
