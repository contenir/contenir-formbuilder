<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\FieldType;

use Contenir\FormBuilder\Definition\FieldDefinition;
use Laminas\Form\Element\Text;
use Laminas\Form\ElementInterface;
use Override;

use function is_int;
use function is_numeric;
use function is_scalar;
use function is_string;

/**
 * @api
 */
class TextField extends AbstractFieldType
{
    #[Override]
    public function key(): string
    {
        return 'text';
    }

    #[Override]
    public function label(): string
    {
        return 'Single-line text';
    }

    /**
     * Emits `pattern` and `maxlength` from the field options, falling back to
     * the first regex / string_length validator when the option is not set.
     *
     * @mago-expect analysis:mixed-assignment Field options are decoded JSON; values are type-checked before use.
     */
    #[Override]
    protected function applyHtml5Hints(ElementInterface $element, FieldDefinition $field): void
    {
        $pattern = $field->options['pattern'] ?? null;
        if (! is_string($pattern) || '' === $pattern) {
            $pattern = $this->validatorOption($field, 'regex', 'pattern');
        }

        $maxLength = $field->options['max_length'] ?? null;
        if (! is_numeric($maxLength)) {
            $maxLength = $this->validatorOption($field, 'string_length', 'max') ?? $maxLength;
        }

        if (null !== $pattern && '' !== $pattern) {
            $element->setAttribute('pattern', $pattern);
        }

        if (is_int($maxLength) || is_string($maxLength) && '' !== $maxLength) {
            $element->setAttribute('maxlength', (string) $maxLength);
        }
    }

    #[Override]
    protected function createElement(FieldDefinition $field): ElementInterface
    {
        return new Text($field->name);
    }

    /**
     * @mago-expect analysis:mixed-assignment Validator options are decoded JSON; the value is type-checked here.
     */
    private function validatorOption(FieldDefinition $field, string $type, string $option): ?string
    {
        foreach ($field->validators as $validator) {
            $value = $validator->options[$option] ?? null;
            if ($type === $validator->type && is_scalar($value)) {
                return (string) $value;
            }
        }

        return null;
    }
}
