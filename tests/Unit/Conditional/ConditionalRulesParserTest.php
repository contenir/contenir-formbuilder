<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Conditional;

use Contenir\FormBuilder\Conditional\ConditionalRulesParser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class ConditionalRulesParserTest extends TestCase
{
    #[Test]
    public function anyCombinatorIsHonoured(): void
    {
        $out = ConditionalRulesParser::parse([
            'combinator' => 'any',
            'conditions' => [['field' => 'a', 'op' => 'equals', 'value' => 'x']],
        ]);

        static::assertArrayHasKey('any', $out['show_when']);
    }

    #[Test]
    public function combinatorDefaultsToAll(): void
    {
        $out = ConditionalRulesParser::parse([
            'conditions' => [['field' => 'a', 'op' => 'equals', 'value' => 'x']],
        ]);

        static::assertArrayHasKey('show_when', $out);
        static::assertArrayHasKey('all', $out['show_when']);
    }

    #[Test]
    public function emptyConditionsReturnsNull(): void
    {
        static::assertNull(ConditionalRulesParser::parse(['combinator' => 'all']));
        static::assertNull(ConditionalRulesParser::parse(['combinator' => 'all', 'conditions' => []]));
    }

    #[Test]
    public function invalidOperatorIsRejected(): void
    {
        $out = ConditionalRulesParser::parse([
            'combinator' => 'all',
            'conditions' => [
                ['field' => 'a', 'op' => 'starts_with', 'value' => 'x'],
            ],
        ]);

        static::assertNull($out);
    }

    #[Test]
    public function nonArrayConditionsReturnNull(): void
    {
        static::assertNull(ConditionalRulesParser::parse(['combinator' => 'all', 'conditions' => 'x']));
    }

    #[Test]
    public function nonArrayConditionsRowIsSkipped(): void
    {
        $out = ConditionalRulesParser::parse([
            'combinator' => 'all',
            'conditions' => [
                'not-an-array',
                ['field' => 'a', 'op' => 'equals', 'value' => 'x'],
            ],
        ]);

        static::assertCount(1, $out['show_when']['all']);
    }

    #[Test]
    public function nonArrayInputReturnsNull(): void
    {
        static::assertNull(ConditionalRulesParser::parse('not an array'));
        static::assertNull(ConditionalRulesParser::parse(null));
    }

    #[Test]
    public function nonScalarRowValuesAreTreatedAsEmpty(): void
    {
        $out = ConditionalRulesParser::parse([
            'conditions' => [
                ['field' => ['a'], 'op' => 'equals', 'value' => 'x'],
                ['field' => 'b', 'op' => 'equals', 'value' => ['x']],
            ],
        ]);

        static::assertSame(['show_when' => ['all' => [['field' => 'b', 'op' => 'equals', 'value' => '']]]], $out);
    }

    #[Test]
    public function rowsMissingFieldOrOpAreDropped(): void
    {
        $out = ConditionalRulesParser::parse([
            'combinator' => 'all',
            'conditions' => [
                ['field' => '', 'op' => 'equals', 'value' => 'x'],
                ['field' => 'a', 'op' => '', 'value' => 'x'],
                ['field' => 'b', 'op' => 'equals', 'value' => 'x'],
            ],
        ]);

        static::assertSame(
            ['show_when' => ['all' => [['field' => 'b', 'op' => 'equals', 'value' => 'x']]]],
            $out,
        );
    }

    #[Test]
    public function trimsTheFieldName(): void
    {
        $out = ConditionalRulesParser::parse([
            'conditions' => [['field' => '  a ', 'op' => 'equals', 'value' => ' x ']],
        ]);

        static::assertSame(['show_when' => ['all' => [['field' => 'a', 'op' => 'equals', 'value' => ' x ']]]], $out);
    }

    #[Test]
    public function unknownCombinatorFallsBackToAll(): void
    {
        $out = ConditionalRulesParser::parse([
            'combinator' => 'maybe',
            'conditions' => [['field' => 'a', 'op' => 'equals', 'value' => 'x']],
        ]);

        static::assertArrayHasKey('all', $out['show_when']);
    }

    #[Test]
    public function valuelessOperatorsStripValue(): void
    {
        $out = ConditionalRulesParser::parse([
            'combinator' => 'all',
            'conditions' => [
                ['field' => 'a', 'op' => 'is_empty', 'value' => 'leftover'],
            ],
        ]);

        static::assertSame(
            ['show_when' => ['all' => [['field' => 'a', 'op' => 'is_empty']]]],
            $out,
        );
    }
}
