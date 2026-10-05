<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\FieldType;

use Contenir\FormBuilder\Definition\FieldDefinition;
use Laminas\Form\Element\Checkbox;
use Laminas\Form\ElementInterface;
use Override;

/**
 * @api
 */
class CheckboxField extends AbstractFieldType
{
    #[Override]
    public function icon(): string
    {
        return 'square-check';
    }

    #[Override]
    public function key(): string
    {
        return 'checkbox';
    }

    #[Override]
    public function label(): string
    {
        return 'Checkbox (single)';
    }

    #[Override]
    public function supportedGroups(): array
    {
        return ['label', 'visibility', 'description', 'default', 'conditional'];
    }

    #[Override]
    protected function createElement(FieldDefinition $field): ElementInterface
    {
        return new Checkbox($field->name);
    }
}
