<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Service;

use Contenir\FormBuilder\Definition\FieldDefinition;
use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Definition\ValidatorDefinition;
use Contenir\FormBuilder\FieldType\FieldTypeRegistry;
use Contenir\FormBuilder\Validator\ValidatorFactory;
use Laminas\Form\Element\Csrf;
use Laminas\Form\Element\Submit;
use Laminas\Form\Element\Text;
use Laminas\Form\Exception\ExceptionInterface as FormException;
use Laminas\Form\FormInterface;
use Laminas\InputFilter\Input;
use Laminas\InputFilter\InputFilter;
use Laminas\Validator\Identical;
use OutOfBoundsException;
use Override;

use function is_string;

/**
 * Assembles a Laminas form instance from a {@see FormDefinition}.
 *
 * The produced form always carries:
 *  - a CSRF element ({@see CSRF_NAME}) that token-checks the submission,
 *  - a honeypot text element ({@see HONEYPOT_NAME}) used by the submission
 *    service to flag bot traffic without rejecting humans,
 *  - a submit button governed by the form's submit_label,
 *
 * plus user-defined fields configured with the curated validators and filters
 * declared on each {@see FieldDefinition}. Validation is configured on the
 * form's input filter; rendering is the host's
 * helper's responsibility.
 *
 * @api
 *
 */
final class FormBuilderService implements FormBuilderInterface
{
    public const string CSRF_NAME     = '_csrf';
    public const string HONEYPOT_NAME = 'hid';

    public function __construct(
        private FieldTypeRegistry $registry,
        private ValidatorFactory $validatorFactory,
    ) {}

    /**
     * Fields whose type is unknown to the registry are skipped. Static types
     * (content blocks) produce no element or input; the renderer emits their
     * body in the row instead.
     *
     * @throws FormException When Laminas rejects an element.
     */
    #[Override]
    public function build(FormDefinition $form): FormInterface
    {
        $builder = new BuilderForm();
        $builder->setAttribute('method', 'post');
        $builder->setAttribute('class', 'formbuilder__form formbuilder__form--stacked');
        $builder->setAttribute('autocomplete', 'on');

        $inputFilter = new InputFilter();

        foreach ($form->getAllFields() as $field) {
            try {
                $type = $this->registry->get($field->type);
            } catch (OutOfBoundsException) {
                continue;
            }

            if ($type->isStatic()) {
                continue;
            }

            $builder->add($type->buildElement($field));
            $inputFilter->add($this->buildInput($field));
        }

        $builder->add($this->buildCsrfElement());

        $builder->add($this->buildHoneypotElement());
        $inputFilter->add($this->buildHoneypotInput());

        $builder->add($this->buildSubmitElement($form));
        $inputFilter->add($this->buildSubmitInput());

        $builder->setInputFilter($inputFilter);

        return $builder;
    }

    /**
     * @mago-expect analysis:mixed-assignment Validator options are decoded JSON; the target is checked before use.
     */
    private function buildConfirm(ValidatorDefinition $validator): ?Identical
    {
        $target = $validator->options['field'] ?? null;
        if (! is_string($target) || '' === $target) {
            return null;
        }

        $identical = new Identical(['token' => $target]);
        if (null !== $validator->message && '' !== $validator->message) {
            $identical->setMessage($validator->message);
        }

        return $identical;
    }

    /**
     * Csrf is an input provider: when the form attaches its input filter
     * defaults, it adds the element's own required, token-checked input, so
     * the builder does not declare one.
     */
    private function buildCsrfElement(): Csrf
    {
        return new Csrf(self::CSRF_NAME, [
            'csrf_options' => [
                'salt' => 'contenir_formbuilder',
            ],
        ]);
    }

    private function buildHoneypotElement(): Text
    {
        $element = new Text(self::HONEYPOT_NAME);
        $element->setLabel('');
        $element->setAttribute('autocomplete', 'off');
        $element->setAttribute('tabindex', '-1');
        $element->setAttribute('aria-hidden', 'true');
        return $element;
    }

    private function buildHoneypotInput(): Input
    {
        $input = new Input(self::HONEYPOT_NAME);
        $input->setRequired(false);
        $input->setAllowEmpty(true);
        return $input;
    }

    /**
     * The `confirm` validator becomes an Identical check against the target
     * field's submitted value; `required` forces the input required.
     */
    private function buildInput(FieldDefinition $field): Input
    {
        $input = new Input($field->name);
        $input->setRequired($field->required);
        $input->setAllowEmpty(! $field->required);

        foreach ($field->validators as $validator) {
            $instance = match ($validator->type) {
                ValidatorFactory::TYPE_REQUIRED => null,
                ValidatorFactory::TYPE_CONFIRM  => $this->buildConfirm($validator),
                default                         => $this->validatorFactory->create($validator),
            };

            if (ValidatorFactory::TYPE_REQUIRED === $validator->type) {
                $input->setRequired(true);
                $input->setAllowEmpty(false);
            }

            if (null !== $instance) {
                $input->getValidatorChain()->attach($instance);
            }
        }

        foreach ($field->filters as $filter) {
            $input->getFilterChain()->attachByName($filter);
        }

        return $input;
    }

    private function buildSubmitElement(FormDefinition $form): Submit
    {
        $element = new Submit('_submit');
        $element->setLabel($form->submitLabel);
        $element->setValue($form->submitLabel);
        $element->setAttribute('class', 'btn btn--primary');
        return $element;
    }

    private function buildSubmitInput(): Input
    {
        $input = new Input('_submit');
        $input->setRequired(false);
        $input->setAllowEmpty(true);
        return $input;
    }
}
