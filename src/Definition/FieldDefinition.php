<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Definition;

/**
 * Immutable description of a single form field.
 *
 * Every field carries a uniform set of universal attributes (label visibility,
 * description, placeholder, default, required) regardless of {@see $type};
 * type-specific overflow lives in {@see $options}.
 *
 * @phpstan-type FieldOptions array<string, mixed>
 * @phpstan-type FieldArray array{
 *     id?: int|null,
 *     type: string,
 *     name: string,
 *     label?: string|null,
 *     show_label?: bool,
 *     description?: string|null,
 *     placeholder?: string|null,
 *     default_value?: string|null,
 *     required?: bool,
 *     col_span?: int,
 *     sort?: int,
 *     options?: FieldOptions,
 *     validators?: list<\Contenir\FormBuilder\Definition\ValidatorDefinition>,
 *     filters?: list<string>,
 *     conditional?: FieldOptions|null
 * }
 *
 * @api
 */
final class FieldDefinition
{
    /**
     * @param FieldOptions $options
     * @param list<ValidatorDefinition> $validators
     * @param list<string> $filters
     * @param FieldOptions|null $conditional
     *
     * @mago-expect lint:excessive-parameter-list Immutable value object; the promoted constructor is its public shape and is called with named arguments.
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $type,
        public readonly string $name,
        public readonly ?string $label = null,
        public readonly bool $showLabel = true,
        public readonly ?string $description = null,
        public readonly ?string $placeholder = null,
        public readonly ?string $defaultValue = null,
        public readonly bool $required = false,
        public readonly int $colSpan = 4,
        public readonly int $sort = 0,
        public readonly array $options = [],
        public readonly array $validators = [],
        public readonly array $filters = [],
        public readonly ?array $conditional = null,
    ) {}
}
