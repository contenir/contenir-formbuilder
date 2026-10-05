<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\FieldType;

use Contenir\FormBuilder\Definition\FieldDefinition;
use Laminas\Form\ElementInterface;
use Override;

use function trim;

/**
 * Shared element configuration used by every concrete field type.
 *
 * Centralises the application of universal field attributes (label visibility,
 * placeholder, default, required attribute, HTML5 hints) so individual
 * subclasses only need to choose the underlying Laminas element class and
 * declare any type-specific HTML5 hints. Required-ness for validation is set
 * on the input filter by {@see \Contenir\FormBuilder\Service\FormBuilderService};
 * this class only emits the HTML5 `required` attribute.
 *
 * @api
 */
abstract class AbstractFieldType implements FieldTypeInterface
{
    /**
     * @mago-expect analysis:mixed-assignment Defaults are scalar or list-shaped per type; Laminas elements accept mixed.
     */
    #[Override]
    public function buildElement(FieldDefinition $field): ElementInterface
    {
        $element = $this->createElement($field);

        $label = $field->showLabel ? $field->label ?? '' : '';
        $element->setLabel($label);

        if ($field->required) {
            $element->setAttribute('required', 'required');
        }

        if (null !== $field->placeholder && '' !== $field->placeholder) {
            $element->setAttribute('placeholder', $field->placeholder);
        }

        $value = $this->valueForDefault($field);
        if (null !== $value && '' !== $value && [] !== $value) {
            $element->setValue($value);
        }

        $existingClass = (string) ($element->getAttribute('class') ?? '');
        $element->setAttribute('class', trim("formbuilder__control {$existingClass}"));

        $this->applyHtml5Hints($element, $field);

        return $element;
    }

    #[Override]
    public function icon(): string
    {
        return 'cursor-text';
    }

    #[Override]
    public function isStatic(): bool
    {
        return false;
    }

    #[Override]
    public function isUserSelectable(): bool
    {
        return true;
    }

    /**
     * Default set — suits the text-like field types (text, textarea,
     * email, url, tel, number). Concrete types whose UI differs (choice
     * groups, hidden fields, file uploads, etc.) override.
     *
     * @return list<string>
     */
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
            'options',
            'conditional',
        ];
    }

    /**
     * Default — validators that fit text-like inputs (length, regex,
     * cross-field confirmation). Concrete types whose vocabulary differs
     * (number / email / etc.) override.
     *
     * @return list<string>
     */
    #[Override]
    public function supportedValidators(): array
    {
        return ['string_length', 'regex', 'confirm'];
    }

    abstract protected function createElement(FieldDefinition $field): ElementInterface;

    /**
     * Default no-op; subclasses override to set type-specific HTML5 attributes.
     *
     * @mago-expect analysis:unused-parameter The base hook ignores both; overrides use them.
     */
    protected function applyHtml5Hints(ElementInterface $element, FieldDefinition $field): void {}

    /**
     * Resolve the field's default value into the shape the underlying
     * Laminas element expects. Scalar by default; the multi-choice types
     * (multiselect / multicheckbox) override to split the comma-joined
     * persisted form into an array of selected option values.
     *
     * @return mixed
     */
    protected function valueForDefault(FieldDefinition $field): mixed
    {
        return $field->defaultValue;
    }
}
