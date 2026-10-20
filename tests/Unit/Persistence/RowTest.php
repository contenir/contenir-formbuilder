<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Persistence;

use Contenir\FormBuilder\Persistence\PersistenceException;
use Contenir\FormBuilder\Persistence\Row;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class RowTest extends TestCase
{
    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function boolProvider(): array
    {
        return [
            'one'                 => [1, true],
            'zero'                => [0, false],
            'the string one'      => ['1', true],
            'the string zero'     => ['0', false],
            'a boolean true'      => [true, true],
            'a boolean false'     => [false, false],
            'NULL is the default' => [null, true],
        ];
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function intProvider(): array
    {
        return [
            'an integer'          => [7, 7],
            'a string of digits'  => ['42', 42],
            'a negative string'   => ['-3', -3],
            'NULL is the default' => [null, 9],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function notAnIntegerProvider(): array
    {
        return [
            'text'                => ['seven'],
            'digits with a space' => ['42 '],
            'a trailing newline'  => ["42\n"],
            'a decimal'           => ['4.2'],
            'a float'             => [4.2],
            'an array'            => [[1]],
        ];
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function stringProvider(): array
    {
        return [
            'a string'            => ['text', 'text'],
            'an integer'          => [7, '7'],
            'empty stays empty'   => ['', ''],
            'NULL is the default' => [null, 'fallback'],
        ];
    }

    #[Test]
    public function aMissingColumnReadsAsItsDefault(): void
    {
        static::assertSame([0, '', null, null, true], [
            (new Row([]))->int('n'),
            (new Row([]))->string('s'),
            (new Row([]))->nullableInt('n'),
            (new Row([]))->nullableString('s'),
            (new Row([]))->bool('b', default: true),
        ]);
    }

    #[Test]
    #[DataProvider('boolProvider')]
    public function readsAFlag(mixed $value, bool $expected): void
    {
        static::assertSame($expected, (new Row(['b' => $value]))->bool('b', default: true));
    }

    #[Test]
    public function readsAJsonList(): void
    {
        static::assertSame(['a', ['b' => 1]], (new Row(['j' => '["a",{"b":1}]']))->jsonList('j'));
    }

    #[Test]
    public function readsAJsonObjectKeepingOnlyItsStringKeys(): void
    {
        static::assertSame(['a' => 1, 'c' => [2]], (new Row(['j' => '{"a":1,"0":"x","c":[2]}']))->jsonMap('j'));
    }

    #[Test]
    public function readsAJsonObjectOrNothingAsNoList(): void
    {
        static::assertSame([[], []], [
            (new Row(['j' => '{"a":1}']))->jsonList('j'),
            (new Row([]))->jsonList('j'),
        ]);
    }

    #[Test]
    #[DataProvider('intProvider')]
    public function readsAnInteger(mixed $value, int $expected): void
    {
        static::assertSame($expected, (new Row(['n' => $value]))->int('n', default: 9));
    }

    #[Test]
    #[DataProvider('stringProvider')]
    public function readsAString(mixed $value, string $expected): void
    {
        static::assertSame($expected, (new Row(['s' => $value]))->string('s', default: 'fallback'));
    }

    #[Test]
    public function readsMissingOrBrokenJsonAsNoObject(): void
    {
        static::assertSame([[], null, null], [
            (new Row(['j' => '{']))->jsonMap('j'),
            (new Row(['j' => '{']))->nullableJsonMap('j'),
            (new Row([]))->nullableJsonMap('j'),
        ]);
    }

    #[Test]
    public function refusesAnArrayAsAString(): void
    {
        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage('Column "s" does not hold a string');

        (new Row(['s' => ['a']]))->string('s');
    }

    #[Test]
    #[DataProvider('notAnIntegerProvider')]
    public function refusesAnythingElseAsAnInteger(mixed $value): void
    {
        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage('Column "n" does not hold an integer');

        (new Row(['n' => $value]))->int('n');
    }
}
