<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\FieldType;

use Contenir\FormBuilder\Definition\FieldDefinition;
use Laminas\Form\Element\Time;
use Laminas\Form\ElementInterface;
use Override;

/**
 * @api
 */
final class TimeField extends AbstractFieldType
{
    #[Override]
    public function icon(): string
    {
        return 'clock';
    }

    #[Override]
    public function key(): string
    {
        return 'time';
    }

    #[Override]
    public function label(): string
    {
        return 'Time';
    }

    #[Override]
    public function supportedGroups(): array
    {
        return ['label', 'visibility', 'description', 'default', 'required', 'conditional'];
    }

    #[Override]
    protected function createElement(FieldDefinition $field): ElementInterface
    {
        return new Time($field->name);
    }
}
