<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\FieldType;

use Contenir\FormBuilder\Definition\FieldDefinition;
use Laminas\Form\Element\Tel;
use Laminas\Form\ElementInterface;
use Override;

use function is_int;
use function is_scalar;
use function is_string;

/**
 * @api
 */
class TelField extends AbstractFieldType
{
    #[Override]
    public function icon(): string
    {
        return 'phone';
    }

    #[Override]
    public function key(): string
    {
        return 'tel';
    }

    #[Override]
    public function label(): string
    {
        return 'Telephone';
    }

    /**
     * Emits `inputmode`, `pattern` (falling back to the first regex validator)
     * and `maxlength` from the field options.
     *
     * @mago-expect analysis:mixed-assignment Field options are decoded JSON; values are type-checked before use.
     */
    #[Override]
    protected function applyHtml5Hints(ElementInterface $element, FieldDefinition $field): void
    {
        $element->setAttribute('inputmode', 'tel');
        $pattern = $field->options['pattern'] ?? null;
        if (! is_string($pattern) || '' === $pattern) {
            foreach ($field->validators as $validator) {
                $validatorPattern = $validator->options['pattern'] ?? null;
                if ('regex' === $validator->type && is_scalar($validatorPattern)) {
                    $pattern = (string) $validatorPattern;
                    break;
                }
            }
        }

        if (is_string($pattern) && '' !== $pattern) {
            $element->setAttribute('pattern', $pattern);
        }

        $maxLength = $field->options['max_length'] ?? null;
        if (is_int($maxLength) || is_string($maxLength) && '' !== $maxLength) {
            $element->setAttribute('maxlength', (string) $maxLength);
        }
    }

    #[Override]
    protected function createElement(FieldDefinition $field): ElementInterface
    {
        return new Tel($field->name);
    }
}
