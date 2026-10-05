<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Render;

use Closure;
use Contenir\FormBuilder\Conditional\RuleEvaluator;
use Contenir\FormBuilder\Definition\FieldDefinition;
use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Definition\GroupDefinition;
use Contenir\FormBuilder\Definition\RowDefinition;
use Contenir\FormBuilder\Definition\SectionDefinition;
use Contenir\FormBuilder\Html\FormContentSanitizer;
use Contenir\FormBuilder\Service\FormBuilderService;
use Laminas\Form\Element\Checkbox;
use Laminas\Form\Element\File;
use Laminas\Form\Element\MultiCheckbox;
use Laminas\Form\Element\Radio;
use Laminas\Form\Element\Select;
use Laminas\Form\Element\Submit;
use Laminas\Form\Element\Textarea;
use Laminas\Form\ElementInterface;
use Laminas\Form\FormInterface;

use function count;
use function htmlspecialchars;
use function in_array;
use function is_array;
use function is_scalar;
use function is_string;
use function json_encode;
use function preg_replace;
use function sprintf;
use function str_contains;
use function str_replace;
use function trim;
use function ucfirst;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const JSON_UNESCAPED_SLASHES;

/**
 * Walks a {@see FormDefinition} and emits the form markup.
 *
 * Rendering is intentionally split from form construction: the
 * {@see \Contenir\FormBuilder\Service\FormBuilderService} produces a
 * validation-ready Laminas form, this helper produces the HTML. The layout
 * is derived from the definition (sections, groups, rows, col-spans), and
 * each input is rendered explicitly per element type — Laminas\Form has no
 * decorator system, so attribute and value emission lives here.
 *
 * Framework-agnostic: holds no view-helper dependencies. Hosts that
 * want to plug in a framework's HTML escaper (Laminas-View's
 * `escapeHtml` plugin, Zend-View's `escape`, etc.) call
 * {@see setEscaper()} with a callable; otherwise the default
 * {@see htmlspecialchars} pass kicks in.
 *
 * The shipping adapter packages provide framework-specific view
 * helpers that wrap this renderer with framework-appropriate
 * escaping, so consuming sites typically call `$this->formMarkup(...)`
 * in templates instead of instantiating this class directly.
 *
 * @api
 *
 * @mago-expect lint:cyclomatic-complexity Kept whole for 2.0 (one renderer per Laminas element type); splitting it is a proposed follow-up.
 * @mago-expect lint:kan-defect Kept whole for 2.0 (one renderer per Laminas element type); splitting it is a proposed follow-up.
 * @mago-expect lint:too-many-methods Kept whole for 2.0 (one renderer per Laminas element type); splitting it is a proposed follow-up.
 */
final class FormMarkup
{
    private const array CLASSES = [
        'section'             => 'formbuilder__section',
        'section-title'       => 'formbuilder__section-title',
        'section-description' => 'formbuilder__section-description',
        'group'               => 'formbuilder__panel',
        'group-description'   => 'formbuilder__panel-description',
        'group-empty'         => 'formbuilder__panel-empty',
        'group-body'          => 'formbuilder__panel-body',
        'row'                 => 'formbuilder__row',
        'field'               => 'formbuilder__field',
        'content'             => 'formbuilder__content',
    ];

    private const array PREVIEW_CLASSES = [
        'section'             => 'form-preview__section',
        'section-title'       => 'form-preview__section-title',
        'section-description' => 'form-preview__section-description',
        'group'               => 'form-preview__group',
        'group-description'   => 'form-preview__group-description',
        'group-empty'         => 'form-preview__group-empty',
        'group-body'          => 'form-preview__group-body',
        'row'                 => 'form-preview__row',
        'field'               => 'form-preview__field',
        'content'             => 'form-preview__content',
    ];

    /** Values of the `multiple` attribute that leave a select single-valued. */
    private const array SINGLE_SELECT = [null, false, '', '0', 0];

    private RuleEvaluator $conditionalEvaluator;

    /** @var Closure(string): string */
    private Closure $escaper;

    private bool $preview = false;

    /** @var array<string, mixed> */
    private array $valueContext = [];

    public function __construct()
    {
        $this->conditionalEvaluator = new RuleEvaluator();
        $this->escaper              = static fn(string $value): string => htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            encoding: 'UTF-8',
        );
    }

    public function render(FormDefinition $definition, FormInterface $form, bool $preview = false): string
    {
        $this->preview      = $preview;
        $this->valueContext = $this->buildValueContext($form);

        if (FormDefinition::LAYOUT_STEPPED === $definition->layoutMode && [] !== $definition->sections) {
            return $this->renderStepped($definition, $form);
        }

        $html = "<form{$this->htmlAttribs($this->formAttributes($form))}>";
        foreach ($definition->sections as $section) {
            $html .= $this->renderSection($section, $form);
        }

        return "{$html}{$this->renderActions($definition, $form)}</form>";
    }

    /**
     * Plug in a host-provided HTML escaper. Useful when the host
     * framework configures escape semantics (e.g. Laminas-View's
     * {@see \Laminas\View\Helper\EscapeHtml}). The callable takes a
     * string and returns the escaped string.
     *
     * @param callable(string): string $escaper
     */
    public function setEscaper(callable $escaper): void
    {
        $this->escaper = $escaper(...);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildValueContext(FormInterface $form): array
    {
        $context = [];
        foreach ($form->getElements() as $element) {
            $context[(string) $element->getName()] = $element->getValue();
        }

        return $context;
    }

    /**
     * Structural wrapper classes. The public path uses `.formbuilder__*` so
     * sites can style rendered forms independently of any project-level
     * `.form` styles; the preview path uses `.form-preview__*` so the admin
     * builder can have a self-contained stylesheet.
     *
     * @param key-of<self::CLASSES> $element
     *
     * @mago-expect analysis:possibly-undefined-string-array-index Both maps share the keys the parameter type allows.
     * @mago-expect analysis:nullable-return-statement Both maps share the keys the parameter type allows.
     * @mago-expect analysis:invalid-return-statement Both maps share the keys the parameter type allows.
     */
    private function classFor(string $element): string
    {
        return ($this->preview ? self::PREVIEW_CLASSES : self::CLASSES)[$element];
    }

    /**
     * Attributes for a field's column wrapper. A conditional field carries
     * its rule as JSON for the client-side evaluator, and starts hidden when
     * the rule fails against the form's current values.
     *
     * @return array<string, string>
     */
    private function columnAttributes(FieldDefinition $field): array
    {
        $attribs = ['class' => $this->classFor('field')];
        if (null === $field->conditional || [] === $field->conditional) {
            return $attribs;
        }

        $encoded = json_encode($field->conditional, JSON_UNESCAPED_SLASHES);
        if (false === $encoded) {
            return $attribs;
        }

        $attribs['data-form-conditional'] = $encoded;
        if (! $this->conditionalEvaluator->shouldShow($field->conditional, $this->valueContext)) {
            $attribs['hidden'] = 'hidden';
        }

        return $attribs;
    }

    /**
     * @param array<string, mixed> $attribs
     *
     * @return array<string, mixed>
     *
     * @mago-expect analysis:mixed-assignment Laminas attributes are untyped; only null and '' count as missing.
     */
    private function defaultId(array $attribs, string $name): array
    {
        $id = $attribs['id'] ?? null;
        if (null === $id || '' === $id) {
            $attribs['id'] = $name;
        }

        return $attribs;
    }

    private function escape(string $value): string
    {
        return ($this->escaper)($value);
    }

    /**
     * The form's own attributes, with `method`, `class` and `autocomplete`
     * defaulted when missing, and `enctype` set when the form has a file input.
     *
     * @return array<string, mixed>
     */
    private function formAttributes(FormInterface $form): array
    {
        $attribs  = $form->getAttributes();
        $defaults = [
            'method'       => 'post',
            'class'        => 'formbuilder__form formbuilder__form--stacked',
            'autocomplete' => 'on',
        ];
        foreach ($defaults as $key => $value) {
            $current = $attribs[$key] ?? null;
            if (null === $current || '' === $current) {
                $attribs[$key] = $value;
            }
        }

        foreach ($form->getElements() as $element) {
            if (! $element instanceof File) {
                continue;
            }

            $attribs['enctype'] = 'multipart/form-data';
        }

        return $attribs;
    }

    /**
     * Renders `name="value"` pairs. Attributes that are null, false, empty or
     * not scalar are omitted, so a boolean attribute set to false is absent
     * rather than present-and-empty.
     *
     * @param array<array-key, mixed> $attribs
     *
     * @mago-expect analysis:mixed-assignment Laminas attributes are untyped; only scalars are rendered.
     */
    private function htmlAttribs(array $attribs): string
    {
        $out = '';
        foreach ($attribs as $name => $value) {
            if (! is_scalar($value) || false === $value || '' === $value) {
                continue;
            }

            $out .= sprintf(' %s="%s"', $name, $this->escape((string) $value));
        }

        return $out;
    }

    /**
     * @return list<string>
     *
     * @mago-expect analysis:mixed-assignment Element values are untyped; non-scalar entries are dropped.
     */
    private function normaliseSelected(mixed $value): array
    {
        $values   = is_array($value) ? $value : [$value];
        $selected = [];
        foreach ($values as $entry) {
            if (! (is_scalar($entry) && '' !== $entry)) {
                continue;
            }

            $selected[] = (string) $entry;
        }

        return $selected;
    }

    /**
     * Flattens Laminas value options, which map a value to either a label or
     * an `{value, label}` spec, into `value => label` strings. Option groups
     * are not supported and are skipped.
     *
     * @param array<array-key, mixed> $valueOptions
     *
     * @return array<string, string>
     *
     * @mago-expect analysis:mixed-assignment Value options are untyped; only scalar values and labels are kept.
     */
    private function optionPairs(array $valueOptions): array
    {
        $pairs = [];
        foreach ($valueOptions as $key => $option) {
            $value = is_array($option) ? $option['value'] ?? null : $key;
            $label = is_array($option) ? $option['label'] ?? '' : $option;
            if (is_scalar($value) && is_scalar($label)) {
                $pairs[(string) $value] = (string) $label;
            }
        }

        return $pairs;
    }

    private function renderActions(FormDefinition $definition, FormInterface $form): string
    {
        $html = "<div class=\"formbuilder__actions formbuilder__actions--{$this->escape($definition->submitAlignment)}\">";

        if ($form->has(FormBuilderService::CSRF_NAME)) {
            $html .= $this->renderInput($form->get(FormBuilderService::CSRF_NAME));
        }

        if ($form->has(FormBuilderService::HONEYPOT_NAME)) {
            $honeypot = $this->renderInput($form->get(FormBuilderService::HONEYPOT_NAME));
            $html     .= "<div class=\"formbuilder__honeypot\" aria-hidden=\"true\">{$honeypot}</div>";
        }

        if ($form->has('_submit')) {
            $html .= $this->renderInput($form->get('_submit'));
        }

        return "{$html}</div>";
    }

    /**
     * The generic `formbuilder__control` class becomes
     * `formbuilder__control--checkbox`, so the hidden-input plus sibling-label
     * custom checkbox applies. The label is emitted after the input.
     *
     * @mago-expect analysis:mixed-assignment Laminas attributes are untyped; the class and id are checked with is_scalar().
     */
    private function renderCheckbox(Checkbox $element): string
    {
        $name            = (string) $element->getName();
        $attribs         = $this->defaultId($element->getAttributes(), $name);
        $attribs['type'] = 'checkbox';
        $attribs['name'] = $name;

        $class = $attribs['class'] ?? '';
        $class = is_scalar($class) ? (string) $class : '';
        $class = trim((string) preg_replace(
            '/\bformbuilder__control\b/',
            replacement: 'formbuilder__control--checkbox',
            subject: $class,
        ));
        if (! str_contains($class, 'formbuilder__control--checkbox')) {
            $class = trim("formbuilder__control--checkbox {$class}");
        }

        $attribs['class'] = $class;

        $checkedValue     = $element->getCheckedValue();
        $uncheckedValue   = $element->getUncheckedValue();
        $attribs['value'] = $checkedValue;
        if ([$checkedValue] === $this->normaliseSelected($element->getValue())) {
            $attribs['checked'] = 'checked';
        }

        $hidden = '' === $uncheckedValue
            ? ''
            : sprintf(
                '<input type="hidden" name="%s" value="%s">',
                $this->escape($name),
                $this->escape((string) $uncheckedValue),
            );

        $label     = (string) $element->getLabel();
        $id        = $attribs['id'];
        $labelHtml = '' === $label || ! is_scalar($id)
            ? ''
            : sprintf(
                '<label class="formbuilder__label" for="%s">%s</label>',
                $this->escape((string) $id),
                $this->escape($label),
            );

        return "{$hidden}<input{$this->htmlAttribs($attribs)}>{$labelHtml}";
    }

    private function renderChoiceList(MultiCheckbox $element, string $type): string
    {
        $name     = (string) $element->getName();
        $selected = $this->normaliseSelected($element->getValue());

        $html = '<div class="formbuilder__control--checkbox-list">';
        foreach ($this->optionPairs($element->getValueOptions()) as $value => $label) {
            $inputId = sprintf(
                '%s-%s',
                $name,
                (string) preg_replace('/[^a-zA-Z0-9_-]/', replacement: '-', subject: $value),
            );
            $attribs = [
                'type'    => $type,
                'name'    => 'radio' === $type ? $name : "{$name}[]",
                'id'      => $inputId,
                'value'   => $value,
                'class'   => 'formbuilder__control--checkbox',
                'checked' => in_array($value, $selected, strict: true) ? 'checked' : null,
            ];

            $html .=
                '<span class="formbuilder__control--checkbox-list-item">'
                . "<input{$this->htmlAttribs($attribs)}>"
                . "<label class=\"formbuilder__label\" for=\"{$this->escape($inputId)}\">{$this->escape(
                    $label,
                )}</label>"
                . '</span>';
        }

        return "{$html}</div>";
    }

    /**
     * Render a static content block. The HTML body lives on
     * `field->options['html']` and runs through FormContentSanitizer
     * here so the output is always safe to dump verbatim into the
     * page (script / iframe / form / event-handler attributes are
     * stripped, disallowed wrappers are unwrapped).
     *
     * @mago-expect analysis:mixed-assignment Field options are decoded JSON; the body is checked with is_string().
     */
    private function renderContent(FieldDefinition $field): string
    {
        $raw = $field->options['html'] ?? '';
        if (! is_string($raw) || trim($raw) === '') {
            return '';
        }

        $body = FormContentSanitizer::sanitize($raw);

        return (
            "<div{$this->htmlAttribs($this->columnAttributes($field))}>"
                . "<div class=\"{$this->classFor('content')}\">{$body}</div>"
                . '</div>'
        );
    }

    /**
     * @mago-expect analysis:mixed-assignment Laminas messages are untyped; each is checked before it is rendered.
     * @mago-expect analysis:unhandled-thrown-type Only fieldsets throw from getMessages(); rendered fields are plain elements.
     */
    private function renderErrors(ElementInterface $element): string
    {
        $items = '';
        foreach ($element->getMessages() as $message) {
            foreach (is_array($message) ? $message : [$message] as $text) {
                if (! is_scalar($text)) {
                    continue;
                }

                $items .= "<li>{$this->escape((string) $text)}</li>";
            }
        }

        return '' === $items ? '' : "<ul class=\"formbuilder__errors\">{$items}</ul>";
    }

    /**
     * Checkboxes draw their own label after the input, so the column label is
     * skipped for them. The controls sit in an `__element` wrapper that owns
     * the positioning context for absolutely placed affordances (such as a
     * date-picker clear button), relative to the input row and not the label.
     */
    private function renderField(FieldDefinition $field, ElementInterface $element): string
    {
        $html = "<div{$this->htmlAttribs($this->columnAttributes($field))}>";

        if (! $element instanceof Checkbox && $field->showLabel && null !== $field->label && '' !== $field->label) {
            $labelClass = $field->required ? 'formbuilder__label formbuilder__label--required' : 'formbuilder__label';
            $html       .= "<label class=\"{$labelClass}\" for=\"{$this->escape($field->name)}\">{$this->escape($field->label)}</label>";
        }

        $html .= "<div class=\"formbuilder__element\">{$this->renderInput($element)}</div>";
        $html .= $this->renderErrors($element);

        if (null !== $field->description && '' !== $field->description) {
            $html .= "<p class=\"formbuilder__description\">{$this->escape($field->description)}</p>";
        }

        return "{$html}</div>";
    }

    private function renderGroup(GroupDefinition $group, FormInterface $form): string
    {
        $rows = '';
        foreach ($group->rows as $row) {
            $rows .= $this->renderRow($row, $form);
        }

        $html = "<fieldset class=\"{$this->classFor('group')}\">";
        if (null !== $group->legend && '' !== $group->legend) {
            $html .= "<legend class=\"formbuilder__legend\">{$this->escape($group->legend)}</legend>";
        }

        if (null !== $group->description && '' !== $group->description) {
            $html .= "<p class=\"{$this->classFor('group-description')}\">{$this->escape($group->description)}</p>";
        }

        $html .= '' === $rows
            ? "<p class=\"{$this->classFor('group-empty')}\"><em>No fields in this group yet.</em></p>"
            : "<div class=\"{$this->classFor('group-body')}\">{$rows}</div>";

        return "{$html}</fieldset>";
    }

    private function renderInput(ElementInterface $element): string
    {
        return match (true) {
            $element instanceof Textarea => $this->renderTextarea($element),
            $element instanceof Radio => $this->renderChoiceList($element, 'radio'),
            $element instanceof MultiCheckbox => $this->renderChoiceList($element, 'checkbox'),
            $element instanceof Checkbox => $this->renderCheckbox($element),
            $element instanceof Select => $this->renderSelect($element),
            $element instanceof Submit => $this->renderSubmit($element),
            default => $this->renderInputTag($element),
        };
    }

    /**
     * @mago-expect analysis:mixed-assignment Element values are untyped; only scalars are rendered.
     */
    private function renderInputTag(ElementInterface $element): string
    {
        $name            = (string) $element->getName();
        $attribs         = $this->defaultId($element->getAttributes(), $name);
        $attribs['name'] = $name;
        $attribs['type'] ??= 'text';

        $value = $element->getValue();
        if (is_scalar($value)) {
            $attribs['value'] = $value;
        }

        return "<input{$this->htmlAttribs($attribs)}>";
    }

    private function renderRow(RowDefinition $row, FormInterface $form): string
    {
        $cols = '';
        foreach ($row->fields as $field) {
            if ('content' === $field->type) {
                $cols .= $this->renderContent($field);
                continue;
            }

            if ($form->has($field->name)) {
                $cols .= $this->renderField($field, $form->get($field->name));
            }
        }

        return '' === $cols ? '' : "<div class=\"{$this->classFor('row')}\">{$cols}</div>";
    }

    private function renderSection(SectionDefinition $section, FormInterface $form): string
    {
        $body = $this->renderSectionBody($section, $form);
        if ('' === $body) {
            return '';
        }

        $heading = '';
        if (null !== $section->legend && '' !== $section->legend) {
            $heading .= "<h2 class=\"{$this->classFor('section-title')}\">{$this->escape($section->legend)}</h2>";
        }

        if (null !== $section->description && '' !== $section->description) {
            $heading .= "<p class=\"{$this->classFor(
                'section-description',
            )}\">{$this->escape($section->description)}</p>";
        }

        return '' === $heading ? $body : "<section class=\"{$this->classFor('section')}\">{$heading}{$body}</section>";
    }

    private function renderSectionBody(SectionDefinition $section, FormInterface $form): string
    {
        $html = '';
        foreach ($section->groups as $group) {
            $html .= $this->renderGroup($group, $form);
        }

        return $html;
    }

    /**
     * Single-value selects pick up `formbuilder__control--select` for the
     * chevron and appearance reset; multi-selects keep the native list.
     *
     * @mago-expect analysis:mixed-assignment Laminas attributes are untyped; the class is checked with is_scalar().
     */
    private function renderSelect(Select $element): string
    {
        $name    = (string) $element->getName();
        $attribs = $this->defaultId($element->getAttributes(), $name);
        $isMulti = ! in_array($attribs['multiple'] ?? null, self::SINGLE_SELECT, strict: true);
        unset($attribs['type']);

        $attribs['name'] = $isMulti ? "{$name}[]" : $name;
        if (! $isMulti) {
            $class            = $attribs['class'] ?? '';
            $class            = is_scalar($class) ? (string) $class : '';
            $attribs['class'] = str_contains($class, 'formbuilder__control--select')
                ? $class
                : trim("{$class} formbuilder__control--select");
        }

        $selected = $this->normaliseSelected($element->getValue());

        $options = '';
        foreach ($this->optionPairs($element->getValueOptions()) as $value => $label) {
            $optAttribs = [
                'value'    => $value,
                'selected' => in_array($value, $selected, strict: true) ? 'selected' : null,
            ];
            $options .= "<option{$this->htmlAttribs($optAttribs)}>{$this->escape($label)}</option>";
        }

        return "<select{$this->htmlAttribs($attribs)}>{$options}</select>";
    }

    /**
     * @param list<SectionDefinition> $sections
     */
    private function renderStepNav(array $sections): string
    {
        $html = '<ol class="formbuilder__steps" role="tablist">';
        foreach ($sections as $index => $section) {
            $isFirst = 0 === $index;
            $label   = null === $section->legend || '' === $section->legend
                ? ucfirst(str_replace(['-', '_'], replace: ' ', subject: $section->key))
                : $section->legend;

            $html .= sprintf(
                '<li><button type="button" class="formbuilder__step-tab%s" data-form-step-target="%s"%s>'
                    . '<span class="formbuilder__step-tab-index">%d</span> %s</button></li>',
                $isFirst ? ' is-active' : '',
                $this->escape($section->key),
                $isFirst ? '' : ' disabled',
                $index + 1,
                $this->escape($label),
            );
        }

        return "{$html}</ol>";
    }

    private function renderStepNavButtons(
        FormDefinition $definition,
        FormInterface $form,
        int $index,
        int $lastIndex,
    ): string {
        $html = '<nav class="formbuilder__step-nav">';
        if (0 !== $index) {
            $html .= '<button type="button" class="btn" data-form-step-prev>Previous</button>';
        }

        $html .= $index === $lastIndex
            ? $this->renderActions($definition, $form)
            : '<button type="button" class="btn btn--primary" data-form-step-next>Next</button>';

        return "{$html}</nav>";
    }

    /**
     * @mago-expect analysis:mixed-assignment Laminas attributes are untyped; the class is checked with is_scalar().
     */
    private function renderStepped(FormDefinition $definition, FormInterface $form): string
    {
        $sections  = $definition->sections;
        $lastIndex = count($sections) - 1;

        $attribs          = $this->formAttributes($form);
        $class            = $attribs['class'] ?? '';
        $attribs['class'] = trim(
            (is_scalar($class) ? (string) $class : '') . ' formbuilder__form--stepped',
        );
        $enctype = $attribs['enctype'] ?? null;
        unset($attribs['enctype']);
        $attribs['data-form-stepper'] = 'true';
        $attribs['enctype']           = $enctype;

        $html = "<form{$this->htmlAttribs($attribs)}>{$this->renderStepNav($sections)}";

        foreach ($sections as $index => $section) {
            $html .= sprintf(
                '<section class="formbuilder__step%s" data-form-step="%s"%s>',
                0 === $index ? ' is-active' : '',
                $this->escape($section->key),
                0 === $index ? '' : ' hidden',
            );

            if (null !== $section->legend && '' !== $section->legend) {
                $html .= "<h2 class=\"formbuilder__step-title\">{$this->escape($section->legend)}</h2>";
            }

            if (null !== $section->description && '' !== $section->description) {
                $html .= "<p class=\"formbuilder__step-description\">{$this->escape($section->description)}</p>";
            }

            $html .= $this->renderSectionBody($section, $form);
            $html .= $this->renderStepNavButtons($definition, $form, $index, $lastIndex);
            $html .= '</section>';
        }

        return "{$html}</form>";
    }

    /**
     * @mago-expect analysis:mixed-assignment Element values are untyped; a non-scalar or empty value falls back to the label.
     */
    private function renderSubmit(Submit $element): string
    {
        $attribs         = $element->getAttributes();
        $attribs['type'] = 'submit';
        $attribs['name'] = (string) $element->getName();

        $value            = $element->getValue();
        $attribs['value'] = is_scalar($value) && '' !== (string) $value
            ? (string) $value
            : (string) $element->getLabel();

        return "<input{$this->htmlAttribs($attribs)}>";
    }

    /**
     * @mago-expect analysis:mixed-assignment Element values are untyped; only scalars are rendered.
     */
    private function renderTextarea(Textarea $element): string
    {
        $name            = (string) $element->getName();
        $attribs         = $this->defaultId($element->getAttributes(), $name);
        $attribs['name'] = $name;
        unset($attribs['type']);

        $value = $element->getValue();
        $body  = is_scalar($value) ? $this->escape((string) $value) : '';

        return "<textarea{$this->htmlAttribs($attribs)}>{$body}</textarea>";
    }
}
