<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\FieldType;

use Contenir\FormBuilder\Definition\FieldDefinition;
use Laminas\Form\Element\Date;
use Laminas\Form\ElementInterface;
use Override;

use function is_string;

/**
 * @api
 */
class DateField extends AbstractFieldType
{
    #[Override]
    public function icon(): string
    {
        return 'calendar';
    }

    #[Override]
    public function key(): string
    {
        return 'date';
    }

    #[Override]
    public function label(): string
    {
        return 'Date';
    }

    #[Override]
    public function supportedGroups(): array
    {
        return ['label', 'visibility', 'description', 'default', 'required', 'options', 'conditional'];
    }

    /**
     * @mago-expect analysis:mixed-assignment Field options are decoded JSON; values are type-checked before use.
     */
    #[Override]
    protected function applyHtml5Hints(ElementInterface $element, FieldDefinition $field): void
    {
        foreach (['min', 'max'] as $attr) {
            $value = $field->options[$attr] ?? null;
            if (is_string($value) && '' !== $value) {
                $element->setAttribute($attr, $value);
            }
        }
    }

    #[Override]
    protected function createElement(FieldDefinition $field): ElementInterface
    {
        return new Date($field->name);
    }
}
