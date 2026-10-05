<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\TestAsset\Factory;

use Contenir\FormBuilder\Definition\FieldDefinition;
use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Definition\GroupDefinition;
use Contenir\FormBuilder\Definition\RowDefinition;
use Contenir\FormBuilder\Definition\SectionDefinition;

/**
 * Builds definitions for tests: every field lands in one section, group and row
 * unless the caller assembles the layout itself.
 */
final class FormDefinitionFactory
{
    /**
     * @param mixed ...$arguments Named FieldDefinition constructor arguments.
     */
    public static function field(string $type, string $name, mixed ...$arguments): FieldDefinition
    {
        /** @var array<string, mixed> $arguments */
        return new FieldDefinition(null, $type, $name, ...$arguments);
    }

    /**
     * @param list<FieldDefinition> $fields
     * @param mixed ...$arguments Named FormDefinition constructor arguments.
     */
    public static function form(array $fields = [], mixed ...$arguments): FormDefinition
    {
        /** @var array<string, mixed> $arguments */
        return new FormDefinition(
            1,
            'contact',
            'Contact',
            ...[...$arguments, 'sections' => [self::section('main', $fields)]],
        );
    }

    /**
     * @param list<SectionDefinition> $sections
     * @param mixed ...$arguments Named FormDefinition constructor arguments.
     */
    public static function formWithSections(array $sections, mixed ...$arguments): FormDefinition
    {
        /** @var array<string, mixed> $arguments */
        return new FormDefinition(1, 'contact', 'Contact', ...[...$arguments, 'sections' => $sections]);
    }

    /**
     * @param list<FieldDefinition> $fields
     */
    public static function section(
        string $key,
        array $fields,
        ?string $legend = null,
        ?string $description = null,
    ): SectionDefinition {
        return new SectionDefinition(
            id: null,
            key: $key,
            legend: $legend,
            description: $description,
            groups: [new GroupDefinition(
                id: null,
                rows: [new RowDefinition(
                    id: null,
                    fields: $fields,
                )],
            )],
        );
    }
}
