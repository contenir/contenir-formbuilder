<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\TestAsset\FieldType;

use Contenir\FormBuilder\Definition\FieldDefinition;
use Contenir\FormBuilder\FieldType\AbstractFieldType;
use Laminas\Form\Element\Text;
use Laminas\Form\ElementInterface;
use Override;

/**
 * A host-defined field type whose element arrives with its own class, as a
 * subclass of AbstractFieldType may do.
 */
final class StyledFieldType extends AbstractFieldType
{
    #[Override]
    public function key(): string
    {
        return 'styled';
    }

    #[Override]
    public function label(): string
    {
        return 'Styled';
    }

    #[Override]
    protected function createElement(FieldDefinition $field): ElementInterface
    {
        $element = new Text($field->name);
        $element->setAttribute('class', 'host-input');

        return $element;
    }
}
