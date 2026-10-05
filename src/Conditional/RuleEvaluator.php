<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Conditional;

use function is_array;
use function is_scalar;
use function str_contains;

/**
 * Evaluates conditional-visibility rules persisted on a {@see \Contenir\FormBuilder\Definition\FieldDefinition}.
 *
 * Rule shape:
 *
 * ```json
 * {
 *   "show_when": {
 *     "all": [
 *       { "field": "contact_method", "op": "equals", "value": "email" }
 *     ]
 *   }
 * }
 * ```
 *
 * Combinators: `all` (AND), `any` (OR). Operators: `equals`, `not_equals`,
 * `is_empty`, `is_not_empty`, `contains`. Missing target fields evaluate
 * as null — `equals` against a missing field is false; `is_empty` is true.
 *
 * The evaluator is intentionally pure so the same logic can be mirrored in
 * `src/js/components/FormConditional.js` for client-side show/hide. Any
 * change to operator semantics here must be reflected there too.
 *
 * @api
 *
 * @mago-expect lint:cyclomatic-complexity Kept whole for 2.0 (one operator per match arm, mirrored by the client-side evaluator); splitting it is a proposed follow-up.
 * @mago-expect lint:kan-defect Kept whole for 2.0 (one operator per match arm, mirrored by the client-side evaluator); splitting it is a proposed follow-up.
 */
class RuleEvaluator
{
    public const string OP_EQUALS       = 'equals';
    public const string OP_NOT_EQUALS   = 'not_equals';
    public const string OP_IS_EMPTY     = 'is_empty';
    public const string OP_IS_NOT_EMPTY = 'is_not_empty';
    public const string OP_CONTAINS     = 'contains';

    /**
     * UI-facing operator vocabulary. Each entry's `needs_value` flag tells the
     * editor whether to render a value input alongside the operator dropdown
     * — `is_empty` / `is_not_empty` ignore any submitted value.
     *
     * @return list<array{op: string, label: string, needs_value: bool}>
     */
    public static function operatorVocabulary(): array
    {
        return [
            ['op' => self::OP_EQUALS, 'label' => 'is equal to', 'needs_value' => true],
            ['op' => self::OP_NOT_EQUALS, 'label' => 'is not equal to', 'needs_value' => true],
            ['op' => self::OP_CONTAINS, 'label' => 'contains', 'needs_value' => true],
            ['op' => self::OP_IS_EMPTY, 'label' => 'is empty', 'needs_value' => false],
            ['op' => self::OP_IS_NOT_EMPTY, 'label' => 'is not empty', 'needs_value' => false],
        ];
    }

    /**
     * @param array<string, mixed>|null $rule
     * @param array<string, mixed>      $values
     *
     * @mago-expect analysis:mixed-assignment Rules are decoded JSON; each level is shape-checked before use.
     */
    public function shouldShow(?array $rule, array $values): bool
    {
        if (null === $rule || [] === $rule) {
            return true;
        }

        $showWhen = $rule['show_when'] ?? null;
        if (! is_array($showWhen) || [] === $showWhen) {
            return true;
        }

        $combinator = null === ($showWhen['any'] ?? null) ? 'all' : 'any';
        $conditions = $showWhen[$combinator] ?? [];
        if (! is_array($conditions) || [] === $conditions) {
            return true;
        }

        if ('any' === $combinator) {
            foreach ($conditions as $condition) {
                if (is_array($condition) && $this->evaluate($condition, $values)) {
                    return true;
                }
            }
            return false;
        }

        foreach ($conditions as $condition) {
            if (! is_array($condition) || ! $this->evaluate($condition, $values)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<array-key, mixed> $condition
     * @param array<string, mixed> $values
     *
     * @mago-expect analysis:mixed-assignment Rule values are decoded JSON; the operators compare them type-safely.
     */
    private function evaluate(array $condition, array $values): bool
    {
        $field = $this->scalarToString($condition['field'] ?? null);
        $op    = $this->scalarToString($condition['op'] ?? null);
        if ('' === $field || '' === $op) {
            return false;
        }

        $current  = $values[$field] ?? null;
        $expected = $condition['value'] ?? null;

        return match ($op) {
            self::OP_EQUALS => $this->stringEquals($current, $expected),
            self::OP_NOT_EQUALS => ! $this->stringEquals($current, $expected),
            self::OP_IS_EMPTY => $this->isEmpty($current),
            self::OP_IS_NOT_EMPTY => ! $this->isEmpty($current),
            self::OP_CONTAINS => $this->stringContains($current, $expected),
            default => false,
        };
    }

    private function isEmpty(mixed $value): bool
    {
        if (null === $value) {
            return true;
        }
        if (is_array($value)) {
            return [] === $value;
        }
        if (is_scalar($value)) {
            return (string) $value === '';
        }
        return true;
    }

    private function scalarToString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @mago-expect analysis:mixed-assignment Multi-value fields hold untyped entries; each is checked with is_scalar().
     */
    private function stringContains(mixed $current, mixed $expected): bool
    {
        $needle = $this->scalarToString($expected);
        if ('' === $needle) {
            return false;
        }
        if (is_array($current)) {
            foreach ($current as $entry) {
                if (is_scalar($entry) && str_contains((string) $entry, $needle)) {
                    return true;
                }
            }
            return false;
        }
        return str_contains($this->scalarToString($current), $needle);
    }

    /**
     * @mago-expect analysis:mixed-assignment Multi-value fields hold untyped entries; each is checked with is_scalar().
     */
    private function stringEquals(mixed $current, mixed $expected): bool
    {
        if (is_array($current)) {
            $expectedString = is_scalar($expected) ? (string) $expected : '';
            foreach ($current as $entry) {
                if (is_scalar($entry) && (string) $entry === $expectedString) {
                    return true;
                }
            }
            return false;
        }
        return $this->scalarToString($current) === $this->scalarToString($expected);
    }
}
