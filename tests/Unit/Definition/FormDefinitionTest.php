<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Definition;

use Contenir\FormBuilder\Definition\FieldDefinition;
use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Definition\GroupDefinition;
use Contenir\FormBuilder\Definition\RowDefinition;
use Contenir\FormBuilder\Definition\SectionDefinition;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;

#[Group('unit')]
final class FormDefinitionTest extends TestCase
{
    #[Test]
    public function defaultsAreApplied(): void
    {
        $form = new FormDefinition(
            id: null,
            slug: 'x',
            title: 'X',
        );

        static::assertSame(FormDefinition::LAYOUT_SINGLE, $form->layoutMode);
        static::assertSame(FormDefinition::STATUS_ACTIVE, $form->status);
        static::assertSame('Submit', $form->submitLabel);
        static::assertSame('left', $form->submitAlignment);
        static::assertNull($form->retentionDays);
        static::assertSame([], $form->sections);
        static::assertSame([], $form->notifications);
    }

    #[Test]
    public function getAllFieldsFlattensNestedStructure(): void
    {
        $field1 = new FieldDefinition(
            id: 1,
            type: 'text',
            name: 'first_name',
        );
        $field2 = new FieldDefinition(
            id: 2,
            type: 'email',
            name: 'email',
        );
        $field3 = new FieldDefinition(
            id: 3,
            type: 'textarea',
            name: 'message',
        );

        $form = new FormDefinition(
            id: 1,
            slug: 'contact',
            title: 'Contact us',
            sections: [
                new SectionDefinition(
                    id: 1,
                    key: 'main',
                    groups: [
                        new GroupDefinition(
                            id: 1,
                            rows: [
                                new RowDefinition(
                                    id: 1,
                                    fields: [$field1, $field2],
                                ),
                                new RowDefinition(
                                    id: 2,
                                    fields: [$field3],
                                ),
                            ],
                        ),
                    ],
                ),
            ],
        );

        $names = array_map(static fn(FieldDefinition $f): string => $f->name, $form->getAllFields());

        static::assertSame(['first_name', 'email', 'message'], $names);
    }
}
