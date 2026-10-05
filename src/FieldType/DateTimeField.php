<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\FieldType;

use Contenir\FormBuilder\Definition\FieldDefinition;
use Laminas\Form\Element\DateTimeLocal;
use Laminas\Form\ElementInterface;
use Override;

/**
 * @api
 */
final class DateTimeField extends AbstractFieldType
{
    #[Override]
    public function icon(): string
    {
        return 'calendar-time';
    }

    #[Override]
    public function key(): string
    {
        return 'datetime';
    }

    #[Override]
    public function label(): string
    {
        return 'Date &amp; time';
    }

    #[Override]
    public function supportedGroups(): array
    {
        return ['label', 'visibility', 'description', 'default', 'required', 'conditional'];
    }

    #[Override]
    protected function createElement(FieldDefinition $field): ElementInterface
    {
        return new DateTimeLocal($field->name);
    }
}
