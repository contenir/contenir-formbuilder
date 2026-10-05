<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Definition;

/**
 * Layout container for fields within a {@see GroupDefinition}.
 *
 * Rows hold up to four fields side-by-side; the sum of {@see FieldDefinition::$colSpan}
 * across child fields must not exceed four. This is enforced at the persistence
 * layer rather than as a runtime invariant on this immutable value object.
 *
 * @api
 */
final class RowDefinition
{
    /** @param list<FieldDefinition> $fields */
    public function __construct(
        public readonly ?int $id,
        public readonly int $sort = 0,
        public readonly array $fields = [],
    ) {}
}
