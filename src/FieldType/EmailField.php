<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\FieldType;

use Contenir\FormBuilder\Definition\FieldDefinition;
use Laminas\Form\Element\Email;
use Laminas\Form\ElementInterface;
use Override;

/**
 * @api
 */
class EmailField extends AbstractFieldType
{
    #[Override]
    public function icon(): string
    {
        return 'mail';
    }

    #[Override]
    public function key(): string
    {
        return 'email';
    }

    #[Override]
    public function label(): string
    {
        return 'Email address';
    }

    #[Override]
    public function supportedGroups(): array
    {
        return [
            'label',
            'visibility',
            'description',
            'placeholder',
            'default',
            'required',
            'validation',
            'conditional',
        ];
    }

    #[Override]
    public function supportedValidators(): array
    {
        return ['confirm'];
    }

    #[Override]
    protected function applyHtml5Hints(ElementInterface $element, FieldDefinition $field): void
    {
        $element->setAttribute('autocomplete', 'email');
        $element->setAttribute('inputmode', 'email');
    }

    #[Override]
    protected function createElement(FieldDefinition $field): ElementInterface
    {
        return new Email($field->name);
    }
}
