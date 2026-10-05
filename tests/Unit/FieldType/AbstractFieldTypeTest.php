<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\FieldType;

use Contenir\FormBuilder\Tests\TestAsset\Factory\FormDefinitionFactory as F;
use Contenir\FormBuilder\Tests\TestAsset\FieldType\StyledFieldType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class AbstractFieldTypeTest extends TestCase
{
    #[Test]
    public function keepsTheClassASubclassGaveTheElement(): void
    {
        $element = (new StyledFieldType())->buildElement(F::field('styled', 'answer'));

        static::assertSame('formbuilder__control host-input', $element->getAttribute('class'));
    }
}
