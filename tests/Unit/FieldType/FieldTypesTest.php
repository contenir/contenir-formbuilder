<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\FieldType;

use Contenir\FormBuilder\Definition\ValidatorDefinition;
use Contenir\FormBuilder\FieldType\CheckboxField;
use Contenir\FormBuilder\FieldType\ContentField;
use Contenir\FormBuilder\FieldType\DateField;
use Contenir\FormBuilder\FieldType\DateTimeField;
use Contenir\FormBuilder\FieldType\EmailField;
use Contenir\FormBuilder\FieldType\FieldTypeInterface;
use Contenir\FormBuilder\FieldType\FieldTypeRegistry;
use Contenir\FormBuilder\FieldType\FileField;
use Contenir\FormBuilder\FieldType\HiddenField;
use Contenir\FormBuilder\FieldType\MulticheckboxField;
use Contenir\FormBuilder\FieldType\MultiselectField;
use Contenir\FormBuilder\FieldType\NumberField;
use Contenir\FormBuilder\FieldType\RadioField;
use Contenir\FormBuilder\FieldType\SelectField;
use Contenir\FormBuilder\FieldType\TelField;
use Contenir\FormBuilder\FieldType\TextareaField;
use Contenir\FormBuilder\FieldType\TextField;
use Contenir\FormBuilder\FieldType\TimeField;
use Contenir\FormBuilder\FieldType\UrlField;
use Contenir\FormBuilder\Tests\TestAsset\Factory\FormDefinitionFactory as F;
use Laminas\Form\Element;
use Laminas\Form\ElementInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class FieldTypesTest extends TestCase
{
    private const array TEXT_GROUPS = [
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

    private const array CHOICE_GROUPS = ['label', 'visibility', 'description', 'required', 'choices', 'conditional'];

    private const array TEXT_VALIDATORS = ['string_length', 'regex', 'confirm'];

    /**
     * @return array<string, array{string}>
     */
    public static function choiceTypeProvider(): array
    {
        return [
            'select'        => ['select'],
            'multiselect'   => ['multiselect'],
            'radio'         => ['radio'],
            'multicheckbox' => ['multicheckbox'],
        ];
    }

    /**
     * @return array<string, array{string, string|null, mixed}>
     */
    public static function defaultValueProvider(): array
    {
        return [
            'text default'                => ['text', 'hello', 'hello'],
            'empty default is not set'    => ['text', '', null],
            'missing default is not set'  => ['text', null, null],
            'multiselect splits defaults' => ['multiselect', ' a, b,,c ', ['a', 'b', 'c']],
            'multiselect without default' => ['multiselect', '', null],
            'multicheckbox list'          => ['multicheckbox', 'a,b', ['a', 'b']],
            'multicheckbox skips blanks'  => ['multicheckbox', 'a, ,b', ['a', 'b']],
            'multicheckbox only commas'   => ['multicheckbox', ' , ', null],
            'multicheckbox no default'    => ['multicheckbox', null, null],
        ];
    }

    /**
     * @return array<string, array{string, class-string<ElementInterface>, string}>
     */
    public static function elementProvider(): array
    {
        return [
            'text'          => ['text', Element\Text::class, 'text'],
            'textarea'      => ['textarea', Element\Textarea::class, 'textarea'],
            'email'         => ['email', Element\Email::class, 'email'],
            'url'           => ['url', Element\Url::class, 'url'],
            'tel'           => ['tel', Element\Tel::class, 'tel'],
            'number'        => ['number', Element\Number::class, 'number'],
            'date'          => ['date', Element\Date::class, 'date'],
            'datetime'      => ['datetime', Element\DateTimeLocal::class, 'datetime-local'],
            'time'          => ['time', Element\Time::class, 'time'],
            'select'        => ['select', Element\Select::class, 'select'],
            'multiselect'   => ['multiselect', Element\Select::class, 'select'],
            'radio'         => ['radio', Element\Radio::class, 'radio'],
            'checkbox'      => ['checkbox', Element\Checkbox::class, 'checkbox'],
            'multicheckbox' => ['multicheckbox', Element\MultiCheckbox::class, 'multi_checkbox'],
            'file'          => ['file', Element\File::class, 'file'],
            'hidden'        => ['hidden', Element\Hidden::class, 'hidden'],
            'content'       => ['content', Element\Hidden::class, 'hidden'],
        ];
    }

    /**
     * @return array<string, array{string, array<string, mixed>, array<string, string>}>
     *
     * @mago-expect lint:halstead A data provider: one field configuration and expected attributes per case.
     */
    public static function html5HintProvider(): array
    {
        $regex  = [new ValidatorDefinition('regex', ['pattern' => '[0-9]+'])];
        $length = [new ValidatorDefinition('string_length', ['max' => 9])];

        return [
            'email input hints'                         => [
                'email',
                [],
                ['autocomplete' => 'email', 'inputmode' => 'email'],
            ],
            'url input mode'                            => ['url', [], ['inputmode' => 'url']],
            'number range and step'                     => [
                'number',
                ['options' => ['min' => 1, 'max' => '10', 'step' => 'x']],
                ['min' => '1', 'max' => '10', 'inputmode' => 'numeric'],
            ],
            'date range'                                => [
                'date',
                ['options' => ['min' => '2026-01-01', 'max' => '']],
                ['min' => '2026-01-01'],
            ],
            'file accept'                               => [
                'file',
                ['options' => ['accept' => '.pdf']],
                ['accept' => '.pdf'],
            ],
            'file without accept'                       => ['file', ['options' => ['accept' => '']], []],
            'textarea rows'                             => [
                'textarea',
                ['options' => ['rows' => '8']],
                ['rows' => '8'],
            ],
            'textarea default rows'                     => ['textarea', [], ['rows' => '5']],
            'textarea non-numeric rows'                 => [
                'textarea',
                ['options' => ['rows' => 'tall']],
                ['rows' => '5'],
            ],
            'textarea fractional rows'                  => [
                'textarea',
                ['options' => ['rows' => '8.5']],
                ['rows' => '8'],
            ],
            'text pattern option'                       => [
                'text',
                ['options' => ['pattern' => '[a-z]+'], 'validators' => $regex],
                ['pattern' => '[a-z]+'],
            ],
            'text pattern from validator'               => ['text', ['validators' => $regex], ['pattern' => '[0-9]+']],
            'text ignores other validators'             => [
                'text',
                ['validators' => [
                    new ValidatorDefinition('regex', ['pattern' => ['x']]),
                    new ValidatorDefinition('email'),
                ]],
                [],
            ],
            'text maxlength option'                     => [
                'text',
                ['options' => ['max_length' => 12], 'validators' => $length],
                ['maxlength' => '12'],
            ],
            'text maxlength from validator'             => ['text', ['validators' => $length], ['maxlength' => '9']],
            'text non-numeric maxlength'                => [
                'text',
                ['options' => ['max_length' => 'wide']],
                ['maxlength' => 'wide'],
            ],
            'text empty maxlength'                      => ['text', ['options' => ['max_length' => '']], []],
            'text non-numeric maxlength with validator' => [
                'text',
                ['options' => ['max_length' => 'wide'], 'validators' => $length],
                ['maxlength' => '9'],
            ],
            'tel input mode'                            => ['tel', [], ['inputmode' => 'tel']],
            'tel pattern option'                        => [
                'tel',
                ['options' => ['pattern' => '\+?[0-9 ]+'], 'validators' => $regex],
                ['inputmode' => 'tel', 'pattern' => '\+?[0-9 ]+'],
            ],
            'tel pattern from validator'                => [
                'tel',
                ['validators' => [new ValidatorDefinition('email'), ...$regex]],
                ['inputmode' => 'tel', 'pattern' => '[0-9]+'],
            ],
            'tel first regex validator wins'            => [
                'tel',
                ['validators' => [...$regex, new ValidatorDefinition('regex', ['pattern' => '[a-z]+'])]],
                ['inputmode' => 'tel', 'pattern' => '[0-9]+'],
            ],
            'tel numeric regex pattern'                 => [
                'tel',
                ['validators' => [new ValidatorDefinition('regex', ['pattern' => 123])]],
                ['inputmode' => 'tel', 'pattern' => '123'],
            ],
            'tel ignores non-regex patterns'            => [
                'tel',
                ['validators' => [new ValidatorDefinition('string_length', ['pattern' => '[a-z]+'])]],
                ['inputmode' => 'tel'],
            ],
            'tel without usable pattern'                => [
                'tel',
                ['validators' => [new ValidatorDefinition('regex')]],
                ['inputmode' => 'tel'],
            ],
            'tel maxlength int'                         => [
                'tel',
                ['options' => ['max_length' => 15]],
                ['inputmode' => 'tel', 'maxlength' => '15'],
            ],
            'tel maxlength string'                      => [
                'tel',
                ['options' => ['max_length' => '15']],
                ['inputmode' => 'tel', 'maxlength' => '15'],
            ],
            'tel empty maxlength'                       => [
                'tel',
                ['options' => ['max_length' => '']],
                ['inputmode' => 'tel'],
            ],
        ];
    }

    /**
     * @return array<string, array{FieldTypeInterface, array{string, string, string, bool, bool, list<string>, list<string>}}>
     */
    public static function metadataProvider(): array
    {
        $contact = [
            'label',
            'visibility',
            'description',
            'placeholder',
            'default',
            'required',
            'validation',
            'conditional',
        ];
        $simple = ['label', 'visibility', 'description', 'default', 'required', 'conditional'];

        return [
            'text'          => [
                new TextField(),
                ['text', 'Single-line text', 'cursor-text', true, false, self::TEXT_GROUPS, self::TEXT_VALIDATORS],
            ],
            'textarea'      => [
                new TextareaField(),
                ['textarea', 'Multi-line text', 'list', true, false, self::TEXT_GROUPS, self::TEXT_VALIDATORS],
            ],
            'email'         => [
                new EmailField(),
                ['email', 'Email address', 'mail', true, false, $contact, ['confirm']],
            ],
            'url'           => [new UrlField(), ['url', 'URL', 'link', true, false, $contact, ['confirm']]],
            'tel'           => [
                new TelField(),
                ['tel', 'Telephone', 'phone', true, false, self::TEXT_GROUPS, self::TEXT_VALIDATORS],
            ],
            'number'        => [
                new NumberField(),
                ['number', 'Number', 'hash', true, false, self::TEXT_GROUPS, ['between', 'confirm']],
            ],
            'date'          => [
                new DateField(),
                [
                    'date',
                    'Date',
                    'calendar',
                    true,
                    false,
                    ['label', 'visibility', 'description', 'default', 'required', 'options', 'conditional'],
                    self::TEXT_VALIDATORS,
                ],
            ],
            'datetime'      => [
                new DateTimeField(),
                ['datetime', 'Date &amp; time', 'calendar-time', true, false, $simple, self::TEXT_VALIDATORS],
            ],
            'time'          => [
                new TimeField(),
                ['time', 'Time', 'clock', true, false, $simple, self::TEXT_VALIDATORS],
            ],
            'select'        => [
                new SelectField(),
                ['select', 'Drop-down (single)', 'select', true, false, self::CHOICE_GROUPS, self::TEXT_VALIDATORS],
            ],
            'multiselect'   => [
                new MultiselectField(),
                ['multiselect', 'Drop-down (multi)', 'list', true, false, self::CHOICE_GROUPS, self::TEXT_VALIDATORS],
            ],
            'radio'         => [
                new RadioField(),
                ['radio', 'Radio group', 'circle-check', true, false, self::CHOICE_GROUPS, self::TEXT_VALIDATORS],
            ],
            'checkbox'      => [
                new CheckboxField(),
                [
                    'checkbox',
                    'Checkbox (single)',
                    'square-check',
                    true,
                    false,
                    ['label', 'visibility', 'description', 'default', 'conditional'],
                    self::TEXT_VALIDATORS,
                ],
            ],
            'multicheckbox' => [
                new MulticheckboxField(),
                ['multicheckbox', 'Checkbox group', 'checks', true, false, self::CHOICE_GROUPS, self::TEXT_VALIDATORS],
            ],
            'file'          => [
                new FileField(),
                [
                    'file',
                    'File upload',
                    'paperclip',
                    true,
                    false,
                    ['label', 'visibility', 'description', 'required', 'options', 'conditional'],
                    self::TEXT_VALIDATORS,
                ],
            ],
            'hidden'        => [
                new HiddenField(),
                [
                    'hidden',
                    'Hidden',
                    'eye-off',
                    true,
                    false,
                    ['label', 'default', 'conditional'],
                    self::TEXT_VALIDATORS,
                ],
            ],
            'content'       => [
                new ContentField(),
                ['content', 'Content / instructions', 'note', true, true, ['conditional'], []],
            ],
        ];
    }

    #[Test]
    #[DataProvider('defaultValueProvider')]
    public function appliesTheDefaultValueInTheElementsShape(string $type, ?string $default, mixed $expected): void
    {
        $element = $this->build($type, 'answer', defaultValue: $default, options: [
            'choices' => [['value' => 'a'], ['value' => 'b'], ['value' => 'c']],
        ]);

        static::assertSame($expected, $element->getValue());
    }

    #[Test]
    public function appliesTheUniversalAttributes(): void
    {
        $element = $this->build('text', 'name', label: 'Your name', required: true, placeholder: 'Jane');

        static::assertSame(
            ['Your name', 'required', 'Jane', 'formbuilder__control'],
            [
                $element->getLabel(),
                $element->getAttribute('required'),
                $element->getAttribute('placeholder'),
                $element->getAttribute('class'),
            ],
        );
    }

    /**
     * @param class-string<ElementInterface> $class
     */
    #[Test]
    #[DataProvider('elementProvider')]
    public function buildsTheMatchingLaminasElement(string $type, string $class, string $htmlType): void
    {
        $element = $this->build($type, 'answer');

        static::assertInstanceOf($class, $element);
        static::assertSame(['answer', $htmlType], [$element->getName(), $element->getAttribute('type')]);
    }

    /**
     * @param array{string, string, string, bool, bool, list<string>, list<string>} $expected
     */
    #[Test]
    #[DataProvider('metadataProvider')]
    public function describesItselfToTheBuilderUi(FieldTypeInterface $type, array $expected): void
    {
        static::assertSame($expected, [
            $type->key(),
            $type->label(),
            $type->icon(),
            $type->isUserSelectable(),
            $type->isStatic(),
            $type->supportedGroups(),
            $type->supportedValidators(),
        ]);
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, string> $expected
     */
    #[Test]
    #[DataProvider('html5HintProvider')]
    public function emitsHtml5Hints(string $type, array $arguments, array $expected): void
    {
        $attributes = $this->build($type, 'answer', ...$arguments)->getAttributes();
        unset($attributes['type'], $attributes['class'], $attributes['name'], $attributes['multiple']);

        static::assertSame($expected, $attributes);
    }

    #[Test]
    #[DataProvider('choiceTypeProvider')]
    public function hasNoChoicesWhenTheChoiceOptionIsNotAList(string $type): void
    {
        $element = $this->build($type, 'pick', options: ['choices' => 'a,b']);

        static::assertTrue($element instanceof Element\Select || $element instanceof Element\MultiCheckbox);
        static::assertSame([], $element->getValueOptions());
    }

    #[Test]
    public function multiselectRendersAsAMultipleSelect(): void
    {
        static::assertSame('multiple', $this->build('multiselect', 'pick')->getAttribute('multiple'));
    }

    #[Test]
    public function omitsEmptyPlaceholderAndRequiredAttributes(): void
    {
        $element = $this->build('text', 'a', placeholder: '');

        static::assertSame(
            [null, null],
            [$element->getAttribute('placeholder'), $element->getAttribute('required')],
        );
    }

    #[Test]
    public function omitsTheLabelWhenItIsHiddenOrMissing(): void
    {
        $hidden  = $this->build('text', 'a', label: 'Shown?', showLabel: false);
        $missing = $this->build('text', 'b');

        static::assertSame(['', ''], [$hidden->getLabel(), $missing->getLabel()]);
    }

    #[Test]
    #[DataProvider('choiceTypeProvider')]
    public function readsChoicesFromTheValueAndLabelList(string $type): void
    {
        $element = $this->build($type, 'pick', options: [
            'choices' => [
                ['value' => 'a', 'label' => 'Apple'],
                ['value' => 2],
                ['value' => 'c', 'label' => ['bad']],
                ['label' => 'No value'],
                ['value' => ['x'], 'label' => 'Array value'],
                'not-a-choice',
                ['value' => 'z', 'label' => 'Zed'],
            ],
        ]);

        static::assertTrue($element instanceof Element\Select || $element instanceof Element\MultiCheckbox);
        static::assertSame(['a' => 'Apple', 2 => '2', 'c' => 'c', 'z' => 'Zed'], $element->getValueOptions());
    }

    #[Test]
    public function registryResolvesEveryBuiltInTypeByKey(): void
    {
        $registry = new FieldTypeRegistry();

        static::assertCount(17, $registry->all());
    }

    private function build(string $type, string $name, mixed ...$arguments): ElementInterface
    {
        return (new FieldTypeRegistry())->get($type)->buildElement(F::field($type, $name, ...$arguments));
    }
}
