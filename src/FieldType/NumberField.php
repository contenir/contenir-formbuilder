<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\FieldType;

use Contenir\FormBuilder\Definition\FieldDefinition;
use Laminas\Form\Element\Number;
use Laminas\Form\ElementInterface;
use Override;

use function is_numeric;

/**
 * @api
 */
class NumberField extends AbstractFieldType
{
    #[Override]
    public function icon(): string
    {
        return 'hash';
    }

    #[Override]
    public function key(): string
    {
        return 'number';
    }

    #[Override]
    public function label(): string
    {
        return 'Number';
    }

    #[Override]
    public function supportedValidators(): array
    {
        return ['between', 'confirm'];
    }

    /**
     * @mago-expect analysis:mixed-assignment Field options are decoded JSON; values are type-checked before use.
     */
    #[Override]
    protected function applyHtml5Hints(ElementInterface $element, FieldDefinition $field): void
    {
        foreach (['min', 'max', 'step'] as $attr) {
            $value = $field->options[$attr] ?? null;
            if (is_numeric($value)) {
                $element->setAttribute($attr, (string) $value);
            }
        }
        $element->setAttribute('inputmode', 'numeric');
    }

    #[Override]
    protected function createElement(FieldDefinition $field): ElementInterface
    {
        return new Number($field->name);
    }
}
