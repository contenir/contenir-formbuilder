<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Definition;

/**
 * Visual fieldset within a {@see SectionDefinition}.
 *
 * An empty {@see $legend} renders without a heading — useful when a section
 * already supplies enough context.
 *
 * @api
 */
final class GroupDefinition
{
    /** @param list<RowDefinition> $rows */
    public function __construct(
        public readonly ?int $id,
        public readonly ?string $legend = null,
        public readonly ?string $description = null,
        public readonly int $sort = 0,
        public readonly array $rows = [],
    ) {}
}
