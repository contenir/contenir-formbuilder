<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\FieldType;

use Contenir\FormBuilder\Definition\FieldDefinition;
use Laminas\Form\Element\Url;
use Laminas\Form\ElementInterface;
use Override;

/**
 * @api
 */
final class UrlField extends AbstractFieldType
{
    #[Override]
    public function icon(): string
    {
        return 'link';
    }

    #[Override]
    public function key(): string
    {
        return 'url';
    }

    #[Override]
    public function label(): string
    {
        return 'URL';
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
        $element->setAttribute('inputmode', 'url');
    }

    #[Override]
    protected function createElement(FieldDefinition $field): ElementInterface
    {
        return new Url($field->name);
    }
}
