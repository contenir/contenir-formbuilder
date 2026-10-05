<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Definition;

use Contenir\FormBuilder\Definition\ValidatorDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class ValidatorDefinitionTest extends TestCase
{
    /**
     * @return array<string, array{array<array-key, mixed>, array{type: string, options: array<string, mixed>, message: string|null}}>
     */
    public static function decodedJsonProvider(): array
    {
        return [
            'scalar type and message become strings'  => [
                ['type' => 5, 'message' => 7],
                ['type' => '5', 'options' => [], 'message' => '7'],
            ],
            'missing type becomes empty'              => [
                [],
                ['type' => '', 'options' => [], 'message' => null],
            ],
            'non-scalar type and message are dropped' => [
                ['type' => ['x'], 'message' => ['y']],
                ['type' => '', 'options' => [], 'message' => null],
            ],
            'non-array options become empty'          => [
                ['type' => 'regex', 'options' => 'pattern'],
                ['type' => 'regex', 'options' => [], 'message' => null],
            ],
            'list options are keyed by string'        => [
                ['type' => 'regex', 'options' => ['a', 'b']],
                ['type' => 'regex', 'options' => ['0' => 'a', '1' => 'b'], 'message' => null],
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array{type: string, options: array<string, mixed>, message: string|null} $expected
     */
    #[Test]
    #[DataProvider('decodedJsonProvider')]
    public function coercesDecodedJsonIntoTypedValues(array $data, array $expected): void
    {
        static::assertSame($expected, ValidatorDefinition::fromArray($data)->toArray());
    }

    #[Test]
    public function defaultsWhenMessageOmitted(): void
    {
        $definition = ValidatorDefinition::fromArray(['type' => 'email']);

        static::assertSame('email', $definition->type);
        static::assertSame([], $definition->options);
        static::assertNull($definition->message);
    }

    #[Test]
    public function roundTripsThroughArray(): void
    {
        $definition = ValidatorDefinition::fromArray([
            'type'    => 'string_length',
            'options' => ['min' => 1, 'max' => 5],
            'message' => 'Too long',
        ]);

        static::assertSame('string_length', $definition->type);
        static::assertSame(['min' => 1, 'max' => 5], $definition->options);
        static::assertSame('Too long', $definition->message);
        static::assertSame(
            ['type' => 'string_length', 'options' => ['min' => 1, 'max' => 5], 'message' => 'Too long'],
            $definition->toArray(),
        );
    }
}
