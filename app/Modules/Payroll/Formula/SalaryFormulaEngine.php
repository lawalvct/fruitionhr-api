<?php

namespace App\Modules\Payroll\Formula;

use App\Modules\Payroll\Models\SalaryComponent;
use Brick\Math\BigRational;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * Validates and interprets the salary formula token schema. It deliberately
 * accepts structured data only: no PHP source, callbacks, property access or
 * eval-like execution is possible.
 */
class SalaryFormulaEngine
{
    public const SCHEMA_VERSION = 1;

    public const MAX_RULES = 20;

    public const MAX_TOKENS = 128;

    public const MAX_DEPTH = 16;

    public const MAX_DEPENDENCIES = 50;

    public const MAX_AMOUNT_KOBO = 9_000_000_000_000_000;

    public const MAX_PERCENTAGE_BASIS_POINTS = 100_000;

    private const OPERATORS = ['+', '-', '*', '/'];

    private const COMPARATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte'];

    /**
     * @return array{schema_version:int,rules:list<array{condition:?array,calculation:list<array>}>}
     */
    public function normalize(array $definition, ?int $ownerComponentId = null): array
    {
        if (($definition['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new SalaryFormulaException('The formula schema_version must be 1.');
        }

        $rules = $definition['rules'] ?? null;
        if (! is_array($rules) || ! array_is_list($rules) || $rules === [] || count($rules) > self::MAX_RULES) {
            throw new SalaryFormulaException('A formula must contain between 1 and '.self::MAX_RULES.' ordered rules.');
        }

        $normalized = [];
        $fallbacks = 0;

        foreach ($rules as $index => $rule) {
            if (! is_array($rule)) {
                throw new SalaryFormulaException("Formula rule {$index} must be an object.");
            }

            $condition = $rule['condition'] ?? null;
            if ($condition === null) {
                $fallbacks++;
            } else {
                $condition = $this->normalizeCondition($condition, $ownerComponentId);
            }

            $calculation = $rule['calculation'] ?? null;
            if (! is_array($calculation) || ! array_is_list($calculation) || $calculation === []) {
                throw new SalaryFormulaException("Formula rule {$index} requires a calculation token list.");
            }

            if (count($calculation) > self::MAX_TOKENS) {
                throw new SalaryFormulaException("Formula rule {$index} exceeds the ".self::MAX_TOKENS.' token limit.');
            }

            $calculation = array_map(
                fn (mixed $token): array => $this->normalizeToken($token, $ownerComponentId, true),
                $calculation,
            );

            $rpn = $this->toRpn($calculation);
            if ($this->inferResultKind($rpn) !== FormulaValue::MONEY) {
                throw new SalaryFormulaException("Formula rule {$index} must calculate a money amount.");
            }

            $normalized[] = ['condition' => $condition, 'calculation' => $calculation];
        }

        if ($fallbacks !== 1 || $normalized[array_key_last($normalized)]['condition'] !== null) {
            throw new SalaryFormulaException('A formula requires exactly one unconditional fallback rule, and it must be last.');
        }

        if (count($this->dependencies(['schema_version' => self::SCHEMA_VERSION, 'rules' => $normalized])) > self::MAX_DEPENDENCIES) {
            throw new SalaryFormulaException('The formula references too many salary components.');
        }

        return ['schema_version' => self::SCHEMA_VERSION, 'rules' => $normalized];
    }

    /**
     * @param  array<int, int>  $componentValues  component id => integer kobo
     * @return array{result_kobo:int,matched_rule_index:int}
     */
    public function evaluate(array $definition, int $basicSalary, array $componentValues = []): array
    {
        if ($basicSalary < 0 || $basicSalary > self::MAX_AMOUNT_KOBO) {
            throw new SalaryFormulaException('The basic salary is outside the supported range.', 'FORMULA_AMOUNT_OUT_OF_RANGE');
        }

        $normalized = $this->normalize($definition);

        foreach ($this->dependencies($normalized) as $dependencyId) {
            if (! array_key_exists($dependencyId, $componentValues)) {
                throw new SalaryFormulaException(
                    "A value is required for referenced salary component {$dependencyId}.",
                    'FORMULA_MISSING_INPUT',
                );
            }

            $amount = $componentValues[$dependencyId];
            if (! is_int($amount) || $amount < 0 || $amount > self::MAX_AMOUNT_KOBO) {
                throw new SalaryFormulaException(
                    "The value for salary component {$dependencyId} is outside the supported range.",
                    'FORMULA_AMOUNT_OUT_OF_RANGE',
                );
            }
        }

        foreach ($normalized['rules'] as $index => $rule) {
            if ($rule['condition'] !== null && ! $this->conditionMatches($rule['condition'], $basicSalary, $componentValues)) {
                continue;
            }

            $value = $this->evaluateRpn($this->toRpn($rule['calculation']), $basicSalary, $componentValues);
            if ($value->number->compareTo(0) < 0) {
                throw new SalaryFormulaException('A salary formula cannot produce a negative amount.', 'FORMULA_NEGATIVE_RESULT');
            }

            if ($value->number->compareTo(self::MAX_AMOUNT_KOBO) > 0) {
                throw new SalaryFormulaException('The salary formula result exceeds the supported amount.', 'FORMULA_AMOUNT_OUT_OF_RANGE');
            }

            try {
                $rounded = $value->number->toScale(0, RoundingMode::HalfUp)->toInt();
            } catch (MathException) {
                throw new SalaryFormulaException(
                    'The salary formula result exceeds the supported amount.',
                    'FORMULA_AMOUNT_OUT_OF_RANGE',
                );
            }

            return ['result_kobo' => $rounded, 'matched_rule_index' => $index];
        }

        // normalize() guarantees the final unconditional rule, so this is a
        // defensive error rather than a silent zero.
        throw new SalaryFormulaException('No salary formula rule matched.', 'FORMULA_NO_MATCH');
    }

    /** @return list<int> */
    public function dependencies(array $definition): array
    {
        $ids = [];

        foreach (($definition['rules'] ?? []) as $rule) {
            foreach (['left', 'right'] as $side) {
                $operand = $rule['condition'][$side] ?? null;
                if (($operand['type'] ?? null) === 'component') {
                    $ids[] = (int) $operand['component_id'];
                }
            }

            foreach (($rule['calculation'] ?? []) as $token) {
                if (($token['type'] ?? null) === 'component') {
                    $ids[] = (int) $token['component_id'];
                }
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    public function checksum(array $definition): string
    {
        return hash('sha256', json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function summary(array $definition): string
    {
        $ruleCount = count($definition['rules'] ?? []);
        $dependencyCount = count($this->dependencies($definition));

        return "{$ruleCount} rule(s), {$dependencyCount} component reference(s), half-up kobo rounding";
    }

    /** @return array{left:array,comparator:string,right:array} */
    private function normalizeCondition(mixed $condition, ?int $ownerComponentId): array
    {
        if (! is_array($condition) || ! in_array($condition['comparator'] ?? null, self::COMPARATORS, true)) {
            throw new SalaryFormulaException('A formula condition has an invalid comparator.');
        }

        $left = $this->normalizeToken($condition['left'] ?? null, $ownerComponentId, false);
        $right = $this->normalizeToken($condition['right'] ?? null, $ownerComponentId, false);

        if ($this->tokenKind($left) !== $this->tokenKind($right)) {
            throw new SalaryFormulaException('Both sides of a formula condition must use the same value type.');
        }

        return ['left' => $left, 'comparator' => $condition['comparator'], 'right' => $right];
    }

    private function normalizeToken(mixed $token, ?int $ownerComponentId, bool $allowSyntax): array
    {
        if (! is_array($token) || ! is_string($token['type'] ?? null)) {
            throw new SalaryFormulaException('Every formula token must be a typed object.');
        }

        return match ($token['type']) {
            'basic' => ['type' => 'basic'],
            'component' => $this->normalizeComponentToken($token, $ownerComponentId),
            'amount' => $this->normalizeAmountToken($token),
            'percentage' => $this->normalizePercentageToken($token),
            'operator' => $allowSyntax && in_array($token['value'] ?? null, self::OPERATORS, true)
                ? ['type' => 'operator', 'value' => $token['value']]
                : throw new SalaryFormulaException('The formula contains an invalid operator.'),
            'left_parenthesis' => $allowSyntax ? ['type' => 'left_parenthesis'] : throw new SalaryFormulaException('Parentheses are not operands.'),
            'right_parenthesis' => $allowSyntax ? ['type' => 'right_parenthesis'] : throw new SalaryFormulaException('Parentheses are not operands.'),
            default => throw new SalaryFormulaException('The formula contains an unsupported token type.'),
        };
    }

    private function normalizeComponentToken(array $token, ?int $ownerComponentId): array
    {
        $id = filter_var($token['component_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new SalaryFormulaException('A component token requires a valid component_id.');
        }

        if ($ownerComponentId !== null && $id === $ownerComponentId) {
            throw new SalaryFormulaException('A salary component cannot reference itself.', 'FORMULA_CYCLE');
        }

        $component = SalaryComponent::query()->find($id);
        if ($component === null) {
            throw new SalaryFormulaException('A referenced salary component does not exist in this company.', 'FORMULA_MISSING_COMPONENT');
        }

        if ($component->isReservedBasicSalaryComponent()) {
            throw new SalaryFormulaException(
                'Use the basic token instead of referencing a legacy Basic Salary component.',
                'FORMULA_RESERVED_BASIC_COMPONENT',
            );
        }

        if ($component->calc_type === SalaryComponent::CALC_PERCENT_OF_GROSS) {
            throw new SalaryFormulaException('Percent-of-gross components cannot be formula dependencies.', 'FORMULA_IMPLICIT_GROSS_CYCLE');
        }

        return ['type' => 'component', 'component_id' => $id];
    }

    private function normalizeAmountToken(array $token): array
    {
        $value = $token['value_kobo'] ?? null;
        if (! is_int($value) || abs($value) > self::MAX_AMOUNT_KOBO) {
            throw new SalaryFormulaException('An amount token must contain an in-range integer value_kobo.');
        }

        return ['type' => 'amount', 'value_kobo' => $value];
    }

    private function normalizePercentageToken(array $token): array
    {
        $value = $token['basis_points'] ?? null;
        if (! is_int($value) || abs($value) > self::MAX_PERCENTAGE_BASIS_POINTS) {
            throw new SalaryFormulaException('A percentage token must contain in-range integer basis_points.');
        }

        return ['type' => 'percentage', 'basis_points' => $value];
    }

    /** @return list<array> */
    private function toRpn(array $tokens): array
    {
        $output = [];
        $operators = [];
        $expectOperand = true;
        $depth = 0;

        foreach ($tokens as $token) {
            $type = $token['type'];

            if (in_array($type, ['basic', 'component', 'amount', 'percentage'], true)) {
                if (! $expectOperand) {
                    throw new SalaryFormulaException('A formula is missing an operator between values.');
                }
                $output[] = $token;
                $expectOperand = false;

                continue;
            }

            if ($type === 'left_parenthesis') {
                if (! $expectOperand || ++$depth > self::MAX_DEPTH) {
                    throw new SalaryFormulaException('The formula has invalid or overly deep parentheses.');
                }
                $operators[] = $token;

                continue;
            }

            if ($type === 'right_parenthesis') {
                if ($expectOperand || $depth === 0) {
                    throw new SalaryFormulaException('The formula has unmatched parentheses.');
                }
                while ($operators !== [] && end($operators)['type'] !== 'left_parenthesis') {
                    $output[] = array_pop($operators);
                }
                array_pop($operators);
                $depth--;
                $expectOperand = false;

                continue;
            }

            if ($type !== 'operator' || $expectOperand) {
                throw new SalaryFormulaException('The formula has an operator in an invalid position.');
            }

            while ($operators !== [] && end($operators)['type'] === 'operator'
                && $this->precedence(end($operators)['value']) >= $this->precedence($token['value'])) {
                $output[] = array_pop($operators);
            }
            $operators[] = $token;
            $expectOperand = true;
        }

        if ($expectOperand || $depth !== 0) {
            throw new SalaryFormulaException('The formula calculation is incomplete.');
        }

        while ($operators !== []) {
            $operator = array_pop($operators);
            if ($operator['type'] !== 'operator') {
                throw new SalaryFormulaException('The formula has unmatched parentheses.');
            }
            $output[] = $operator;
        }

        return $output;
    }

    private function inferResultKind(array $rpn): string
    {
        $stack = [];
        foreach ($rpn as $token) {
            if ($token['type'] !== 'operator') {
                $stack[] = $this->tokenKind($token);

                continue;
            }

            if (count($stack) < 2) {
                throw new SalaryFormulaException('The formula calculation is incomplete.');
            }
            $right = array_pop($stack);
            $left = array_pop($stack);
            $stack[] = $this->resultKind($left, $right, $token['value']);
        }

        if (count($stack) !== 1) {
            throw new SalaryFormulaException('The formula calculation is invalid.');
        }

        return $stack[0];
    }

    private function evaluateRpn(array $rpn, int $basicSalary, array $componentValues): FormulaValue
    {
        $stack = [];

        foreach ($rpn as $token) {
            if ($token['type'] !== 'operator') {
                $stack[] = $this->operandValue($token, $basicSalary, $componentValues);

                continue;
            }

            $right = array_pop($stack);
            $left = array_pop($stack);
            $kind = $this->resultKind($left->kind, $right->kind, $token['value']);

            if ($token['value'] === '/' && $right->number->compareTo(0) === 0) {
                throw new SalaryFormulaException('A salary formula attempted to divide by zero.', 'FORMULA_DIVISION_BY_ZERO');
            }

            $number = match ($token['value']) {
                '+' => $left->number->plus($right->number),
                '-' => $left->number->minus($right->number),
                '*' => $left->number->multipliedBy($right->number),
                '/' => $left->number->dividedBy($right->number),
            };

            $stack[] = new FormulaValue($number, $kind);
        }

        return $stack[0];
    }

    private function conditionMatches(array $condition, int $basicSalary, array $componentValues): bool
    {
        $left = $this->operandValue($condition['left'], $basicSalary, $componentValues);
        $right = $this->operandValue($condition['right'], $basicSalary, $componentValues);
        $comparison = $left->number->compareTo($right->number);

        return match ($condition['comparator']) {
            'eq' => $comparison === 0,
            'neq' => $comparison !== 0,
            'gt' => $comparison > 0,
            'gte' => $comparison >= 0,
            'lt' => $comparison < 0,
            'lte' => $comparison <= 0,
        };
    }

    private function operandValue(array $token, int $basicSalary, array $componentValues): FormulaValue
    {
        return match ($token['type']) {
            'basic' => new FormulaValue(BigRational::of($basicSalary), FormulaValue::MONEY),
            'component' => new FormulaValue(BigRational::of($componentValues[$token['component_id']]), FormulaValue::MONEY),
            'amount' => new FormulaValue(BigRational::of($token['value_kobo']), FormulaValue::MONEY),
            'percentage' => new FormulaValue(BigRational::ofFraction($token['basis_points'], 10_000), FormulaValue::SCALAR),
        };
    }

    private function tokenKind(array $token): string
    {
        return $token['type'] === 'percentage' ? FormulaValue::SCALAR : FormulaValue::MONEY;
    }

    private function resultKind(string $left, string $right, string $operator): string
    {
        if (in_array($operator, ['+', '-'], true)) {
            if ($left !== $right) {
                throw new SalaryFormulaException('Addition and subtraction require matching value types.');
            }

            return $left;
        }

        if ($operator === '*') {
            if ($left === FormulaValue::MONEY && $right === FormulaValue::MONEY) {
                throw new SalaryFormulaException('A money amount cannot be multiplied by another money amount.');
            }

            return $left === FormulaValue::MONEY || $right === FormulaValue::MONEY
                ? FormulaValue::MONEY
                : FormulaValue::SCALAR;
        }

        if ($left === FormulaValue::SCALAR && $right === FormulaValue::MONEY) {
            throw new SalaryFormulaException('A percentage cannot be divided by a money amount.');
        }

        return $left === FormulaValue::MONEY && $right === FormulaValue::MONEY
            ? FormulaValue::SCALAR
            : $left;
    }

    private function precedence(string $operator): int
    {
        return in_array($operator, ['*', '/'], true) ? 2 : 1;
    }
}
