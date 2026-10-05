<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\FieldType;

use Contenir\FormBuilder\Definition\FieldDefinition;
use Laminas\Form\Element\Select;
use Laminas\Form\ElementInterface;
use Override;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function is_array;
use function is_scalar;

/**
 * @api
 */
final class MultiselectField extends AbstractFieldType
{
    #[Override]
    public function icon(): string
    {
        return 'list';
    }

    #[Override]
    public function key(): string
    {
        return 'multiselect';
    }

    #[Override]
    public function label(): string
    {
        return 'Drop-down (multi)';
    }

    #[Override]
    public function supportedGroups(): array
    {
        return ['label', 'visibility', 'description', 'required', 'choices', 'conditional'];
    }

    #[Override]
    protected function createElement(FieldDefinition $field): ElementInterface
    {
        $element = new Select($field->name);
        $element->setValueOptions($this->extractChoices($field));
        $element->setAttribute('multiple', 'multiple');
        return $element;
    }

    #[Override]
    protected function valueForDefault(FieldDefinition $field): mixed
    {
        if (null === $field->defaultValue || '' === $field->defaultValue) {
            return null;
        }
        return array_values(array_filter(
            array_map('trim', explode(',', $field->defaultValue)),
            static fn(string $v): bool => '' !== $v,
        ));
    }

    /**
     * @return array<string, string>
     *
     * @mago-expect analysis:mixed-assignment Choices are decoded JSON options; each entry is checked here.
     */
    private function extractChoices(FieldDefinition $field): array
    {
        $raw = $field->options['choices'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $entry) {
            if (! is_array($entry) || ! is_scalar($entry['value'] ?? null)) {
                continue;
            }

            $value       = (string) $entry['value'];
            $label       = $entry['label'] ?? null;
            $out[$value] = is_scalar($label) ? (string) $label : $value;
        }

        return $out;
    }
}
