<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\FieldType;

use Contenir\FormBuilder\Definition\FieldDefinition;
use Laminas\Form\Element\Hidden;
use Laminas\Form\ElementInterface;
use Override;

/**
 * @api
 */
class HiddenField extends AbstractFieldType
{
    #[Override]
    public function icon(): string
    {
        return 'eye-off';
    }

    #[Override]
    public function key(): string
    {
        return 'hidden';
    }

    #[Override]
    public function label(): string
    {
        return 'Hidden';
    }

    #[Override]
    public function supportedGroups(): array
    {
        return ['label', 'default', 'conditional'];
    }

    #[Override]
    protected function createElement(FieldDefinition $field): ElementInterface
    {
        return new Hidden($field->name);
    }
}
