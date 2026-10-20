<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Persistence;

use Contenir\FormBuilder\Persistence\Json;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use const INF;

#[Group('unit')]
final class JsonTest extends TestCase
{
    /**
     * @return array<string, array{mixed, array<array-key, mixed>|null}>
     */
    public static function decodeProvider(): array
    {
        return [
            'NULL'            => [null, null],
            'empty'           => ['', null],
            'not a string'    => [12, null],
            'not JSON'        => ['{', null],
            'a JSON scalar'   => ['"text"', null],
            'an object'       => ['{"a":1}', ['a' => 1]],
            'a list'          => ['[1,2]', [1, 2]],
            'an empty object' => ['{}', []],
        ];
    }

    /**
     * @return array<string, array{array<array-key, mixed>, string|null}>
     */
    public static function encodeProvider(): array
    {
        return [
            'empty is NULL'                 => [[], null],
            'slashes and Unicode unescaped' => [
                ['url' => 'https://example.com/a', 'name' => 'Zoë'],
                '{"url":"https://example.com/a","name":"Zoë"}',
            ],
            'a list'                        => [['a', 'b'], '["a","b"]'],
            'unencodable is NULL'           => [['n' => INF], null],
        ];
    }

    /**
     * @param array<array-key, mixed>|null $expected
     */
    #[Test]
    #[DataProvider('decodeProvider')]
    public function decodesAColumnValue(mixed $value, ?array $expected): void
    {
        static::assertSame($expected, Json::decode($value));
    }

    /**
     * @param array<array-key, mixed> $value
     */
    #[Test]
    #[DataProvider('encodeProvider')]
    public function encodesAColumnValue(array $value, ?string $expected): void
    {
        static::assertSame($expected, Json::encode($value));
    }
}
