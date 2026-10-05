<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Conditional;

use Contenir\FormBuilder\Conditional\RuleEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

use function array_column;

#[Group('unit')]
final class RuleEvaluatorTest extends TestCase
{
    /**
     * @return array<string, array{array<array-key, mixed>}>
     */
    public static function incompleteConditionProvider(): array
    {
        return [
            'missing field'    => [['op' => 'is_empty']],
            'missing operator' => [['field' => 'x']],
            'array field name' => [['field' => ['x'], 'op' => 'is_empty']],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>, mixed, bool}>
     */
    public static function multiValueProvider(): array
    {
        return [
            'contains matches an entry'          => [['op' => 'contains', 'value' => 'ell'], ['x', 'hello'], true],
            'contains misses every entry'        => [['op' => 'contains', 'value' => 'zz'], ['x', 'hello'], false],
            'contains skips non-scalar entries'  => [['op' => 'contains', 'value' => 'a'], [['a']], false],
            'equals skips non-scalar entries'    => [['op' => 'equals', 'value' => 'a'], [['a']], false],
            'equals with non-scalar expectation' => [['op' => 'equals', 'value' => ['a']], ['', 'b'], true],
            'object value counts as empty'       => [['op' => 'is_empty'], new stdClass(), true],
            'object value never equals a string' => [['op' => 'equals', 'value' => ''], new stdClass(), true],
            'boolean true compares as one'       => [['op' => 'equals', 'value' => '1'], true, true],
        ];
    }

    /** @return list<array{string, mixed, mixed, bool}> */
    public static function operatorMatrix(): array
    {
        return [
            ['equals', 'a', 'a', true],
            ['equals', 'a', 'b', false],
            ['equals', 1, '1', true],
            ['equals', null, '', true],
            ['not_equals', 'a', 'b', true],
            ['not_equals', 'a', 'a', false],
            ['is_empty', '', null, true],
            ['is_empty', 'x', null, false],
            ['is_empty', null, null, true],
            ['is_empty', [], null, true],
            ['is_not_empty', 'x', null, true],
            ['is_not_empty', '', null, false],
            ['contains', 'hello world', 'world', true],
            ['contains', 'hello', 'world', false],
            ['contains', 'hello', '', false],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function rulesThatAlwaysShowProvider(): array
    {
        return [
            'show_when is not an array'  => [['show_when' => 'yes']],
            'show_when is empty'         => [['show_when' => []]],
            'conditions are not a list'  => [['show_when' => ['all' => 'x']]],
            'conditions are empty'       => [['show_when' => ['all' => []]]],
            'null any falls back to all' => [['show_when' => ['any' => null, 'all' => []]]],
        ];
    }

    #[Test]
    public function allCombinatorHidesWhenAConditionIsNotAnArray(): void
    {
        $rule = ['show_when' => ['all' => ['not-a-condition']]];

        static::assertFalse((new RuleEvaluator())->shouldShow($rule, []));
    }

    #[Test]
    public function allCombinatorRequiresEveryConditionToMatch(): void
    {
        $rule = [
            'show_when' => [
                'all' => [
                    ['field' => 'a', 'op' => 'equals', 'value' => '1'],
                    ['field' => 'b', 'op' => 'equals', 'value' => '2'],
                ],
            ],
        ];

        static::assertTrue((new RuleEvaluator())->shouldShow($rule, ['a' => '1', 'b' => '2']));
        static::assertFalse((new RuleEvaluator())->shouldShow($rule, ['a' => '1', 'b' => '3']));
    }

    #[Test]
    public function anyCombinatorRequiresAtLeastOneToMatch(): void
    {
        $rule = [
            'show_when' => [
                'any' => [
                    ['field' => 'a', 'op' => 'equals', 'value' => '1'],
                    ['field' => 'b', 'op' => 'equals', 'value' => '2'],
                ],
            ],
        ];

        static::assertTrue((new RuleEvaluator())->shouldShow($rule, ['a' => '1', 'b' => '0']));
        static::assertTrue((new RuleEvaluator())->shouldShow($rule, ['a' => '0', 'b' => '2']));
        static::assertFalse((new RuleEvaluator())->shouldShow($rule, ['a' => '0', 'b' => '0']));
    }

    #[Test]
    public function anyCombinatorSkipsConditionsThatAreNotArrays(): void
    {
        $rule = ['show_when' => ['any' => ['not-a-condition', ['field' => 'x', 'op' => 'is_empty']]]];

        static::assertTrue((new RuleEvaluator())->shouldShow($rule, []));
    }

    #[Test]
    #[DataProvider('multiValueProvider')]
    public function comparesMultiValueAndUnusualFieldValues(array $condition, mixed $current, bool $expected): void
    {
        $rule = ['show_when' => ['all' => [['field' => 'x', ...$condition]]]];

        static::assertSame($expected, (new RuleEvaluator())->shouldShow($rule, ['x' => $current]));
    }

    #[Test]
    public function emptyRuleAlwaysShows(): void
    {
        static::assertTrue((new RuleEvaluator())->shouldShow([], ['x' => 'y']));
    }

    #[Test]
    public function equalsAgainstArrayValueMatchesIfAnyEntryMatches(): void
    {
        $rule = ['show_when' => ['all' => [['field' => 'tags', 'op' => 'equals', 'value' => 'php']]]];

        static::assertTrue((new RuleEvaluator())->shouldShow($rule, ['tags' => ['php', 'js']]));
        static::assertFalse((new RuleEvaluator())->shouldShow($rule, ['tags' => ['ruby', 'js']]));
    }

    #[Test]
    public function equalsAgainstMissingFieldFailsClosed(): void
    {
        $rule = ['show_when' => ['all' => [['field' => 'method', 'op' => 'equals', 'value' => 'email']]]];

        static::assertFalse((new RuleEvaluator())->shouldShow($rule, []));
    }

    #[Test]
    public function equalsMatchesAndDifferingValueDoesNot(): void
    {
        $rule = ['show_when' => ['all' => [['field' => 'method', 'op' => 'equals', 'value' => 'email']]]];

        static::assertTrue((new RuleEvaluator())->shouldShow($rule, ['method' => 'email']));
        static::assertFalse((new RuleEvaluator())->shouldShow($rule, ['method' => 'phone']));
    }

    #[Test]
    #[DataProvider('incompleteConditionProvider')]
    public function hidesWhenAConditionIsIncomplete(array $condition): void
    {
        $rule = ['show_when' => ['all' => [$condition]]];

        static::assertFalse((new RuleEvaluator())->shouldShow($rule, []));
    }

    #[Test]
    public function notEqualsAgainstMissingFieldMatches(): void
    {
        $rule = ['show_when' => ['all' => [['field' => 'method', 'op' => 'not_equals', 'value' => 'email']]]];

        static::assertTrue((new RuleEvaluator())->shouldShow($rule, []));
    }

    #[Test]
    public function nullRuleAlwaysShows(): void
    {
        static::assertTrue((new RuleEvaluator())->shouldShow(null, []));
    }

    #[Test]
    #[DataProvider('operatorMatrix')]
    public function operatorBehaviour(string $op, mixed $current, mixed $expected, bool $shouldMatch): void
    {
        $rule = ['show_when' => ['all' => [['field' => 'x', 'op' => $op, 'value' => $expected]]]];

        static::assertSame(
            $shouldMatch,
            (new RuleEvaluator())->shouldShow($rule, ['x' => $current]),
        );
    }

    #[Test]
    public function operatorVocabularyListsEveryOperatorWithItsValueRequirement(): void
    {
        $vocabulary = RuleEvaluator::operatorVocabulary();

        static::assertSame(
            [
                RuleEvaluator::OP_EQUALS       => true,
                RuleEvaluator::OP_NOT_EQUALS   => true,
                RuleEvaluator::OP_CONTAINS     => true,
                RuleEvaluator::OP_IS_EMPTY     => false,
                RuleEvaluator::OP_IS_NOT_EMPTY => false,
            ],
            array_column($vocabulary, 'needs_value', 'op'),
        );
    }

    #[Test]
    public function ruleWithoutShowWhenAlwaysShows(): void
    {
        static::assertTrue((new RuleEvaluator())->shouldShow(['something' => 'else'], []));
    }

    #[Test]
    #[DataProvider('rulesThatAlwaysShowProvider')]
    public function showsWhenTheRuleHasNoUsableConditions(array $rule): void
    {
        static::assertTrue((new RuleEvaluator())->shouldShow($rule, []));
    }

    #[Test]
    public function unknownOperatorFailsClosed(): void
    {
        $rule = ['show_when' => ['all' => [['field' => 'x', 'op' => 'starts_with', 'value' => 'foo']]]];

        static::assertFalse((new RuleEvaluator())->shouldShow($rule, ['x' => 'foobar']));
    }
}
