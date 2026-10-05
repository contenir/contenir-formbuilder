<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Service;

use Contenir\FormBuilder\Definition\ValidatorDefinition;
use Contenir\FormBuilder\FieldType\FieldTypeRegistry;
use Contenir\FormBuilder\Service\BuilderForm;
use Contenir\FormBuilder\Service\FormBuilderService;
use Contenir\FormBuilder\Tests\TestAsset\Factory\FormDefinitionFactory as F;
use Contenir\FormBuilder\Validator\ValidatorFactory;
use Laminas\Filter\StringTrim;
use Laminas\Form\Element\Csrf;
use Laminas\Form\Element\Submit;
use Laminas\Form\Element\Text;
use Laminas\InputFilter\Input;
use Laminas\InputFilter\InputFilter;
use Laminas\Validator\EmailAddress;
use Laminas\Validator\Identical;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_filter;
use function array_keys;
use function array_map;
use function array_values;

#[Group('unit')]
final class FormBuilderServiceTest extends TestCase
{
    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function blankValueProvider(): array
    {
        return [
            'whitespace honeypot'                 => [FormBuilderService::HONEYPOT_NAME, '  ', true],
            'whitespace submit'                   => ['_submit', '  ', true],
            'whitespace optional field'           => ['optional', '  ', true],
            'empty field with required rule'      => ['rule', '', false],
            'whitespace field with required rule' => ['rule', '  ', false],
        ];
    }

    #[Test]
    public function addsAnElementAndInputPerDataField(): void
    {
        $form = $this->builder()->build(F::form([
            F::field('text', 'name'),
            F::field('content', 'intro', options: ['html' => '<p>Hi</p>']),
            F::field('nonexistent', 'ghost'),
            F::field('email', 'email'),
        ]));

        static::assertEqualsCanonicalizing(
            ['name', 'email', FormBuilderService::CSRF_NAME, FormBuilderService::HONEYPOT_NAME, '_submit'],
            array_keys($form->getElements()),
        );
        static::assertEqualsCanonicalizing(
            ['name', 'email', FormBuilderService::CSRF_NAME, FormBuilderService::HONEYPOT_NAME, '_submit'],
            array_keys($this->inputFilter($form)->getInputs()),
        );
    }

    #[Test]
    public function addsCsrfHoneypotAndSubmitControls(): void
    {
        $form = $this->builder()->build(F::form(submitLabel: 'Send'));

        $csrf     = $form->get(FormBuilderService::CSRF_NAME);
        $honeypot = $form->get(FormBuilderService::HONEYPOT_NAME);
        $submit   = $form->get('_submit');

        static::assertInstanceOf(Csrf::class, $csrf);
        static::assertInstanceOf(Text::class, $honeypot);
        static::assertInstanceOf(Submit::class, $submit);
        static::assertSame(
            ['', 'off', '-1', 'true', 'Send', 'Send', 'btn btn--primary'],
            [
                $honeypot->getLabel(),
                $honeypot->getAttribute('autocomplete'),
                $honeypot->getAttribute('tabindex'),
                $honeypot->getAttribute('aria-hidden'),
                $submit->getLabel(),
                $submit->getValue(),
                $submit->getAttribute('class'),
            ],
        );
    }

    #[Test]
    public function attachesCuratedValidatorsAndNamedFilters(): void
    {
        $filter = $this->inputFilter($this->builder()->build(F::form([
            F::field('text', 'email', validators: [new ValidatorDefinition('email')], filters: [StringTrim::class]),
        ])));
        $input = $filter->get('email');

        static::assertInstanceOf(Input::class, $input);
        static::assertSame(
            [[EmailAddress::class], 1],
            [$this->validatorClasses($input), $input->getFilterChain()->count()],
        );
    }

    #[Test]
    #[DataProvider('blankValueProvider')]
    public function blankValuesPassOnlyWhereEmptyIsAllowed(string $name, string $value, bool $valid): void
    {
        $filter = $this->inputFilter($this->builder()->build(F::form([
            F::field('text', 'rule', validators: [new ValidatorDefinition('required')]),
            F::field('text', 'optional'),
        ])));
        $input = $filter->get($name);
        static::assertInstanceOf(Input::class, $input);
        $input->setValue($value);

        static::assertSame($valid, $input->isValid());
    }

    #[Test]
    public function buildsAPostFormWithTheBuilderClasses(): void
    {
        $form = $this->builder()->build(F::form());

        static::assertInstanceOf(BuilderForm::class, $form);
        static::assertSame(
            ['post', 'formbuilder__form formbuilder__form--stacked', 'on'],
            [$form->getAttribute('method'), $form->getAttribute('class'), $form->getAttribute('autocomplete')],
        );
    }

    #[Test]
    public function confirmValidatorAcceptsAMatchingTargetValue(): void
    {
        $filter = $this->inputFilter($this->builder()->build(F::form([
            F::field('email', 'email'),
            F::field('email', 'email_again', validators: [new ValidatorDefinition('confirm', ['field' => 'email'])]),
        ])));
        $input = $filter->get('email_again');
        static::assertInstanceOf(Input::class, $input);
        $input->setValue('a@b.c');

        static::assertTrue($input->isValid(['email' => 'a@b.c']));
    }

    #[Test]
    public function confirmValidatorChecksTheTargetFieldWithItsMessage(): void
    {
        $filter = $this->inputFilter($this->builder()->build(F::form([
            F::field('email', 'email'),
            F::field('email', 'email_again', validators: [
                new ValidatorDefinition('confirm', ['field' => 'email'], 'Emails differ'),
            ]),
        ])));
        $input = $filter->get('email_again');
        static::assertInstanceOf(Input::class, $input);
        $identical = array_values(array_filter(
            array_column($input->getValidatorChain()->getValidators(), 'instance'),
            static fn(object $validator): bool => $validator instanceof Identical,
        ))[0];

        static::assertInstanceOf(Identical::class, $identical);
        static::assertFalse($identical->isValid('a@b.c', ['email' => 'x@y.z']));
        static::assertSame(['Emails differ'], array_values($identical->getMessages()));
    }

    #[Test]
    public function confirmValidatorWithoutTargetOrMessageIsHandled(): void
    {
        $filter = $this->inputFilter($this->builder()->build(F::form([
            F::field('text', 'no_target', validators: [new ValidatorDefinition('confirm')]),
            F::field('text', 'no_message', validators: [new ValidatorDefinition('confirm', ['field' => 'x'])]),
        ])));
        $noTarget  = $filter->get('no_target');
        $noMessage = $filter->get('no_message');
        static::assertInstanceOf(Input::class, $noTarget);
        static::assertInstanceOf(Input::class, $noMessage);

        static::assertSame([[], [Identical::class]], [
            $this->validatorClasses($noTarget),
            $this->validatorClasses($noMessage),
        ]);
    }

    #[Test]
    public function csrfElementUsesTheFormBuilderSalt(): void
    {
        $csrf = $this->builder()->build(F::form())->get(FormBuilderService::CSRF_NAME);

        static::assertInstanceOf(Csrf::class, $csrf);
        static::assertSame('contenir_formbuilder', $csrf->getCsrfValidator()->getSalt());
    }

    #[Test]
    public function csrfIsRequiredAndHoneypotAndSubmitAreOptional(): void
    {
        $filter = $this->inputFilter($this->builder()->build(F::form()));

        static::assertSame(
            [true, false, false],
            [
                $filter->get(FormBuilderService::CSRF_NAME)->isRequired(),
                $filter->get(FormBuilderService::HONEYPOT_NAME)->isRequired(),
                $filter->get('_submit')->isRequired(),
            ],
        );
    }

    #[Test]
    public function requiredFieldsAndRequiredValidatorsMakeTheInputRequired(): void
    {
        $filter = $this->inputFilter($this->builder()->build(F::form([
            F::field('text', 'flag', required: true),
            F::field('text', 'rule', validators: [new ValidatorDefinition('required')]),
            F::field('text', 'optional'),
        ])));

        static::assertSame(
            [true, true, false],
            [
                $filter->get('flag')->isRequired(),
                $filter->get('rule')->isRequired(),
                $filter->get('optional')->isRequired(),
            ],
        );
    }

    private function builder(): FormBuilderService
    {
        return new FormBuilderService(new FieldTypeRegistry(), new ValidatorFactory());
    }

    private function inputFilter(object $form): InputFilter
    {
        static::assertInstanceOf(BuilderForm::class, $form);
        $filter = $form->getInputFilter();
        static::assertInstanceOf(InputFilter::class, $filter);

        return $filter;
    }

    /**
     * @return list<class-string>
     */
    private function validatorClasses(Input $input): array
    {
        return array_map(
            static fn(array $entry): string => $entry['instance']::class,
            $input->getValidatorChain()->getValidators(),
        );
    }
}
