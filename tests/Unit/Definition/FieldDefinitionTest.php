<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Definition;

use Contenir\FormBuilder\Definition\FieldDefinition;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class FieldDefinitionTest extends TestCase
{
    #[Test]
    public function fieldDefaultsToAFourColumnSpanAtTheFirstPosition(): void
    {
        $field = new FieldDefinition(
            id: null,
            type: 'text',
            name: 'name',
        );

        static::assertSame([4, 0], [$field->colSpan, $field->sort]);
    }
}
