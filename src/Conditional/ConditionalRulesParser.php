<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Conditional;

use function array_filter;
use function array_map;
use function array_values;
use function in_array;
use function is_array;
use function is_scalar;
use function trim;

/**
 * Translates the admin field-edit POST shape into the rule JSON consumed by
 * {@see RuleEvaluator}.
 *
 * Expected POST shape (from `_field-form.phtml`):
 *
 *     conditional[combinator]                   = "all" | "any"
 *     conditional[conditions][i][field]         = "<other field name>"
 *     conditional[conditions][i][op]            = "equals" | "not_equals" | …
 *     conditional[conditions][i][value]         = "<scalar>"
 *
 * Empty rows (no field selected, or no operator) are dropped silently —
 * pre-rendered placeholder rows the user never filled in shouldn't make
 * the persisted rule larger than necessary. Operators that don't take a
 * value ({@see RuleEvaluator::OP_IS_EMPTY}, {@see RuleEvaluator::OP_IS_NOT_EMPTY})
 * have their `value` stripped on the way in.
 *
 * Returns null when no usable conditions remain — null means "always show",
 * so the form ends up unrolling cleanly to a non-conditional field on save.
 *
 * @api
 */
final class ConditionalRulesParser
{
    private const array VALID_OPS = [
        RuleEvaluator::OP_EQUALS,
        RuleEvaluator::OP_NOT_EQUALS,
        RuleEvaluator::OP_IS_EMPTY,
        RuleEvaluator::OP_IS_NOT_EMPTY,
        RuleEvaluator::OP_CONTAINS,
    ];

    private const array VALUELESS_OPS = [
        RuleEvaluator::OP_IS_EMPTY,
        RuleEvaluator::OP_IS_NOT_EMPTY,
    ];

    /**
     * @return array<string, mixed>|null
     *
     * @mago-expect analysis:mixed-assignment POST data is untyped; each value is checked before use.
     * @mago-expect lint:prefer-first-class-callable Xdebug records no branch coverage through a first-class callable.
     */
    public static function parse(mixed $post): ?array
    {
        if (! is_array($post)) {
            return null;
        }

        $combinator = ($post['combinator'] ?? null) === 'any' ? 'any' : 'all';
        $rawRows    = $post['conditions'] ?? [];
        if (! is_array($rawRows)) {
            return null;
        }

        $normalise  = /** @return array{field: string, op: string, value?: string}|null */
        static fn(mixed $row): ?array => self::normaliseRow($row);
        $conditions = array_values(array_filter(array_map($normalise, $rawRows)));

        if ([] === $conditions) {
            return null;
        }

        return ['show_when' => [$combinator => $conditions]];
    }

    /**
     * @return array{field: string, op: string, value?: string}|null
     */
    private static function normaliseRow(mixed $row): ?array
    {
        if (! is_array($row)) {
            return null;
        }

        $field = trim(self::scalarString($row['field'] ?? null));
        $op    = self::scalarString($row['op'] ?? null);
        if ('' === $field || ! in_array($op, self::VALID_OPS, strict: true)) {
            return null;
        }

        $condition = ['field' => $field, 'op' => $op];
        if (! in_array($op, self::VALUELESS_OPS, strict: true)) {
            $condition['value'] = self::scalarString($row['value'] ?? null);
        }

        return $condition;
    }

    private static function scalarString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
