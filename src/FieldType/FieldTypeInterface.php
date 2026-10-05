<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\FieldType;

use Contenir\FormBuilder\Definition\FieldDefinition;
use Laminas\Form\ElementInterface;

/**
 * Strategy contract for converting a {@see FieldDefinition} into a
 * {@see ElementInterface} ready to be added to a Laminas form.
 *
 * The set of available types is exposed to the builder UI through
 * {@see FieldTypeRegistry::all()}; each implementation declares its own
 * machine name ({@see key()}) and human label.
 *
 * @api
 */
interface FieldTypeInterface
{
    public function buildElement(FieldDefinition $field): ElementInterface;

    /**
     * Tabler icon name shown alongside the type label in the
     * Add Field dropdown picker.
     */
    public function icon(): string;

    /**
     * Whether this type renders as a non-input static block (instructional
     * text / HTML) rather than collecting user data. The {@see \Contenir\FormBuilder\Service\FormBuilderService}
     * skips static fields when assembling the Laminas form so they don't
     * appear as inputs in the input filter or the submitted POST; the
     * the host's renderer handles them
     * separately and emits sanitized HTML in their slot.
     */
    public function isStatic(): bool;

    /**
     * Whether the user can pick this type in the builder UI. Some
     * implementations exist only as auto-injected internals (CSRF, honeypot).
     */
    public function isUserSelectable(): bool;

    /**
     * Machine identifier persisted in `form_field.type`.
     */
    public function key(): string;

    /**
     * Human-readable label shown in the field-type picker.
     */
    public function label(): string;

    /**
     * Set of UI group keys the field-edit form should render for this
     * type. The full vocabulary is:
     *
     *   label         — Label input (the human-facing question)
     *   visibility    — show_label checkbox
     *   description   — Description / help text
     *   placeholder   — Placeholder input
     *   default       — Default value input
     *   required      — Required checkbox
     *   validation    — Server-side validators panel
     *   options       — Type-specific HTML5 options (pattern, min/max, rows…)
     *   choices       — Choices list (value/label pairs)
     *   conditional   — Conditional-show rules editor
     *
     * Order doesn't matter — the partial walks the vocabulary in a
     * fixed display order and renders each block only when its key
     * is in the returned set.
     *
     * @return list<string>
     */
    public function supportedGroups(): array;

    /**
     * Set of validator type keys (from {@see \Contenir\FormBuilder\Validator\ValidatorFactory})
     * that make sense for this field type. Used to filter the Validation
     * section of the field-edit form so a tel field doesn't offer Email-
     * address or Number-range validators.
     *
     * Returning an empty list hides the Validation section entirely.
     *
     * @return list<string>
     */
    public function supportedValidators(): array;
}
