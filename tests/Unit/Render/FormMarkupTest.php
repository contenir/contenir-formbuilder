<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Render;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Definition\GroupDefinition;
use Contenir\FormBuilder\Definition\RowDefinition;
use Contenir\FormBuilder\Definition\SectionDefinition;
use Contenir\FormBuilder\Render\FormMarkup;
use Contenir\FormBuilder\Tests\TestAsset\Factory\FormDefinitionFactory as F;
use Laminas\Form\Element;
use Laminas\Form\ElementInterface;
use Laminas\Form\Form;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function strtoupper;

/**
 * Renders hand-built Laminas forms, so each element type and edge case is
 * exercised without the builder or a session-backed CSRF element.
 */
#[Group('unit')]
final class FormMarkupTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>|null, string}>
     */
    public static function conditionalProvider(): array
    {
        $rule = ['show_when' => ['all' => [['field' => 'mode', 'op' => 'equals', 'value' => 'b']]]];

        return [
            'no rule'          => [null, '<div class="formbuilder__field"><div class="formbuilder__element">'],
            'empty rule'       => [[], '<div class="formbuilder__field"><div class="formbuilder__element">'],
            'rule that passes' => [
                ['show_when' => ['all' => [['field' => 'mode', 'op' => 'equals', 'value' => 'a']]]],
                'data-form-conditional="{&quot;show_when&quot;:{&quot;all&quot;:[{&quot;field&quot;:&quot;mode&quot;,&quot;op&quot;:&quot;equals&quot;,&quot;value&quot;:&quot;a&quot;}]}}"><div',
            ],
            'rule that fails'  => [$rule, '}]}}" hidden="hidden"><div'],
            'unencodable rule' => [
                ['show_when' => "\xB1"],
                '<div class="formbuilder__field"><div class="formbuilder__element">',
            ],
        ];
    }

    /**
     * @return array<string, array{ElementInterface, string}>
     *
     * @mago-expect lint:halstead A data provider: one element and expected markup per case.
     */
    public static function inputProvider(): array
    {
        $text = new Element\Text('name', ['label' => 'Name']);
        $text->setAttributes(['id' => 'custom-id', 'placeholder' => 'Jane', 'disabled' => false, 'data-x' => ['no']]);
        $text->setValue('Ann <b>');

        $untyped = new Element('raw');
        $untyped->setValue(['not', 'scalar']);

        $textarea = new Element\Textarea('msg');
        $textarea->setValue("Hi\n<there>");

        $arrayTextarea = new Element\Textarea('msg');
        $arrayTextarea->setValue(['x']);

        $select = new Element\Select('pick');
        $select->setValueOptions([
            'a'     => 'Apple',
            'b'     => 'Banana',
            'c'     => ['value' => 'cherry', 'label' => 'Cherry'],
            'bad'   => ['x' => 1],
            'group' => ['label' => 'G', 'options' => []],
        ]);
        $select->setValue('b');

        $styledSelect = new Element\Select('pick');
        $styledSelect->setAttribute('class', 'formbuilder__control--select wide');
        $styledSelect->setValueOptions(['a' => 'A']);

        $arrayClassSelect = new Element\Select('pick');
        $arrayClassSelect->setAttribute('class', ['x']);

        $multi = new Element\Select('tags');
        $multi->setAttribute('multiple', 'multiple');
        $multi->setValueOptions(['a' => 'A', 'b' => 'B']);
        $multi->setValue(['a', ['nested'], 'b']);

        $radio = new Element\Radio('size');
        $radio->setValueOptions(['s m' => 'Small', 'l' => 'Large']);
        $radio->setValue('l');

        $boxes = new Element\MultiCheckbox('days');
        $boxes->setValueOptions(['mon' => 'Mon', 'tue' => 'Tue']);
        $boxes->setValue(['tue']);

        $checkbox = new Element\Checkbox('agree', ['label' => 'I agree']);
        $checkbox->setAttribute('class', 'formbuilder__control big');
        $checkbox->setValue('1');

        $plainCheckbox = new Element\Checkbox('agree');
        $plainCheckbox->setUncheckedValue('');

        $oddIdCheckbox = new Element\Checkbox('agree', ['label' => 'Agree']);
        $oddIdCheckbox->setAttribute('id', ['x']);
        $oddIdCheckbox->setUseHiddenElement(false);

        $arrayClassCheckbox = new Element\Checkbox('agree');
        $arrayClassCheckbox->setAttribute('class', ['x']);
        $arrayClassCheckbox->setUseHiddenElement(false);

        $submit = new Element\Submit('_submit', ['label' => 'Send']);

        $submitWithValue = new Element\Submit('_submit');
        $submitWithValue->setValue('Go');

        $arraySubmit = new Element\Submit('_submit', ['label' => 'Fallback']);
        $arraySubmit->setValue(['x']);

        $falseSubmit = new Element\Submit('_submit', ['label' => 'Fallback']);
        $falseSubmit->setValue(false);

        $paddedClassCheckbox = new Element\Checkbox('agree');
        $paddedClassCheckbox->setAttribute('class', ' formbuilder__control ');

        return [
            'text input'                => [
                $text,
                '<input type="text" name="name" id="custom-id" placeholder="Jane" value="Ann &lt;b&gt;">',
            ],
            'untyped input'             => [$untyped, '<input name="raw" id="raw" type="text">'],
            'textarea'                  => [
                $textarea,
                "<textarea name=\"msg\" id=\"msg\">Hi\n&lt;there&gt;</textarea>",
            ],
            'textarea non-scalar value' => [$arrayTextarea, '<textarea name="msg" id="msg"></textarea>'],
            'select'                    => [
                $select,
                '<select name="pick" id="pick" class="formbuilder__control--select"><option value="a">Apple</option><option value="b" selected="selected">Banana</option><option value="cherry">Cherry</option></select>',
            ],
            'select with select class'  => [
                $styledSelect,
                '<select name="pick" class="formbuilder__control--select wide" id="pick"><option value="a">A</option></select>',
            ],
            'select non-scalar class'   => [
                $arrayClassSelect,
                '<select name="pick" class="formbuilder__control--select" id="pick"></select>',
            ],
            'multiple select'           => [
                $multi,
                '<select name="tags[]" multiple="multiple" id="tags"><option value="a" selected="selected">A</option><option value="b" selected="selected">B</option></select>',
            ],
            'radio list'                => [
                $radio,
                '<div class="formbuilder__control--checkbox-list"><span class="formbuilder__control--checkbox-list-item"><input type="radio" name="size" id="size-s-m" value="s m" class="formbuilder__control--checkbox"><label class="formbuilder__label" for="size-s-m">Small</label></span><span class="formbuilder__control--checkbox-list-item"><input type="radio" name="size" id="size-l" value="l" class="formbuilder__control--checkbox" checked="checked"><label class="formbuilder__label" for="size-l">Large</label></span></div>',
            ],
            'checkbox list'             => [
                $boxes,
                '<div class="formbuilder__control--checkbox-list"><span class="formbuilder__control--checkbox-list-item"><input type="checkbox" name="days[]" id="days-mon" value="mon" class="formbuilder__control--checkbox"><label class="formbuilder__label" for="days-mon">Mon</label></span><span class="formbuilder__control--checkbox-list-item"><input type="checkbox" name="days[]" id="days-tue" value="tue" class="formbuilder__control--checkbox" checked="checked"><label class="formbuilder__label" for="days-tue">Tue</label></span></div>',
            ],
            'checkbox'                  => [
                $checkbox,
                '<input type="hidden" name="agree" value="0"><input type="checkbox" name="agree" class="formbuilder__control--checkbox big" id="agree" value="1" checked="checked"><label class="formbuilder__label" for="agree">I agree</label>',
            ],
            'checkbox without extras'   => [
                $plainCheckbox,
                '<input type="checkbox" name="agree" id="agree" class="formbuilder__control--checkbox" value="1">',
            ],
            'checkbox odd id'           => [
                $oddIdCheckbox,
                '<input type="hidden" name="agree" value="0"><input type="checkbox" name="agree" class="formbuilder__control--checkbox" value="1">',
            ],
            'checkbox array class'      => [
                $arrayClassCheckbox,
                '<input type="hidden" name="agree" value="0"><input type="checkbox" name="agree" class="formbuilder__control--checkbox" id="agree" value="1">',
            ],
            'submit uses label'         => [$submit, '<input type="submit" name="_submit" value="Send">'],
            'submit uses value'         => [$submitWithValue, '<input type="submit" name="_submit" value="Go">'],
            'submit non-scalar value'   => [$arraySubmit, '<input type="submit" name="_submit" value="Fallback">'],
            'submit false value'        => [$falseSubmit, '<input type="submit" name="_submit" value="Fallback">'],
            'checkbox padded class'     => [
                $paddedClassCheckbox,
                '<input type="hidden" name="agree" value="0"><input type="checkbox" name="agree" class="formbuilder__control--checkbox" id="agree" value="1">',
            ],
        ];
    }

    /**
     * @return array<string, array{string|null, string|null, string}>
     */
    public static function sectionHeadingProvider(): array
    {
        return [
            'no heading'       => [
                null,
                null,
                '<form method="POST" class="formbuilder__form formbuilder__form--stacked" autocomplete="on"><fieldset',
            ],
            'legend only'      => [
                'Title',
                '',
                '<section class="formbuilder__section"><h2 class="formbuilder__section-title">Title</h2><fieldset',
            ],
            'description only' => [
                '',
                'Text',
                '<section class="formbuilder__section"><p class="formbuilder__section-description">Text</p><fieldset',
            ],
        ];
    }

    #[Test]
    public function emptySectionRendersNothing(): void
    {
        $definition = F::formWithSections([new SectionDefinition(
            id: null,
            key: 's',
            legend: 'Hidden',
        )]);

        static::assertStringNotContainsString('Hidden', (new FormMarkup())->render($definition, new Form()));
    }

    #[Test]
    public function fileInputSwitchesTheEncoding(): void
    {
        $form = new Form();
        $form->add(new Element\Text('name'));
        $form->add(new Element\File('cv'));

        static::assertStringContainsString(
            'enctype="multipart/form-data"',
            (new FormMarkup())->render(F::form(), $form),
        );
    }

    #[Test]
    public function hostEscaperReplacesTheDefault(): void
    {
        $markup = new FormMarkup();
        $markup->setEscaper(strtoupper(...));
        $form = new Form();
        $form->add(new Element\Text('name'));

        $html = $markup->render(F::form([F::field('text', 'name', label: 'name')]), $form);

        static::assertStringContainsString('<label class="formbuilder__label" for="NAME">NAME</label>', $html);
    }

    #[Test]
    #[DataProvider('conditionalProvider')]
    public function marksConditionalColumns(?array $rule, string $expected): void
    {
        $form = new Form();
        $form->add(new Element\Text('lead'));
        $form->add(new Element\Text('mode'));
        $form->get('mode')->setValue('a');
        $form->add(new Element\Text('extra'));

        $html = (new FormMarkup())->render(F::form([F::field('text', 'extra', conditional: $rule)]), $form);

        static::assertStringContainsString($expected, $html);
    }

    #[Test]
    public function optionalLabelHasNoRequiredModifierAndCheckboxSkipsTheColumnLabel(): void
    {
        $form = new Form();
        $form->add(new Element\Text('name'));
        $form->add(new Element\Checkbox('agree'));
        $definition = F::form([
            F::field('text', 'name', label: 'Name', description: ''),
            F::field('checkbox', 'agree', label: 'Agree'),
            F::field('text', 'missing', label: 'Not in form'),
        ]);

        $html = (new FormMarkup())->render($definition, $form);

        static::assertStringContainsString('<label class="formbuilder__label" for="name">Name</label>', $html);
        static::assertStringNotContainsString('for="agree">Agree', $html);
        static::assertStringNotContainsString('Not in form', $html);
    }

    #[Test]
    public function previewUsesThePreviewClasses(): void
    {
        $definition = F::formWithSections([
            new SectionDefinition(
                id: null,
                key: 's',
                legend: 'Section',
                description: 'About',
                groups: [
                    new GroupDefinition(
                        id: null,
                        legend: 'Group',
                        description: 'Details',
                        rows: [
                            new RowDefinition(
                                id: null,
                                fields: [F::field('content', 'c', options: ['html' => 'Hi'])],
                            ),
                        ],
                    ),
                    new GroupDefinition(id: null),
                ],
            ),
        ]);

        $html = (new FormMarkup())->render($definition, new Form(), preview: true);

        static::assertStringContainsString(
            '<section class="form-preview__section"><h2 class="form-preview__section-title">Section</h2>'
                . '<p class="form-preview__section-description">About</p>'
                . '<fieldset class="form-preview__group"><legend class="formbuilder__legend">Group</legend>'
                . '<p class="form-preview__group-description">Details</p><div class="form-preview__group-body">'
                . '<div class="form-preview__row"><div class="form-preview__field"><div class="form-preview__content">Hi</div></div></div>'
                . '</div></fieldset><fieldset class="form-preview__group">'
                . '<p class="form-preview__group-empty"><em>No fields in this group yet.</em></p></fieldset></section>',
            $html,
        );
    }

    #[Test]
    public function publicRenderUsesTheFormbuilderClasses(): void
    {
        $definition = F::formWithSections([
            new SectionDefinition(
                id: null,
                key: 's',
                legend: 'Section',
                description: 'About',
                groups: [
                    new GroupDefinition(
                        id: null,
                        legend: '',
                        description: 'Details',
                        rows: [new RowDefinition(id: null)],
                    ),
                ],
            ),
        ]);

        $html = (new FormMarkup())->render($definition, new Form());

        static::assertStringContainsString(
            '<section class="formbuilder__section"><h2 class="formbuilder__section-title">Section</h2>'
                . '<p class="formbuilder__section-description">About</p><fieldset class="formbuilder__panel">'
                . '<p class="formbuilder__panel-description">Details</p>'
                . '<p class="formbuilder__panel-empty"><em>No fields in this group yet.</em></p></fieldset></section>',
            $html,
        );
    }

    #[Test]
    #[DataProvider('inputProvider')]
    public function rendersEachElementType(ElementInterface $element, string $expected): void
    {
        $form = new Form();
        $form->add($element);
        $definition = F::form([F::field('text', (string) $element->getName(), showLabel: false)]);

        static::assertStringContainsString(
            "<div class=\"formbuilder__element\">{$expected}</div>",
            (new FormMarkup())->render($definition, $form),
        );
    }

    #[Test]
    public function rendersEveryRowOfAGroup(): void
    {
        $form = new Form();
        $form->add(new Element\Text('first'));
        $form->add(new Element\Text('second'));
        $definition = F::formWithSections([new SectionDefinition(
            id: null,
            key: 's',
            groups: [new GroupDefinition(
                id: null,
                rows: [
                    new RowDefinition(
                        id: null,
                        fields: [F::field('text', 'first', showLabel: false)],
                    ),
                    new RowDefinition(
                        id: null,
                        fields: [F::field('text', 'second', showLabel: false)],
                    ),
                ],
            )],
        )]);

        $html = (new FormMarkup())->render($definition, $form);

        static::assertStringContainsString(
            '<input type="text" name="first" id="first"></div></div></div><div class="formbuilder__row">'
                . '<div class="formbuilder__field"><div class="formbuilder__element"><input type="text" name="second"',
            $html,
        );
    }

    #[Test]
    public function rendersFieldsThatFollowAContentBlock(): void
    {
        $form = new Form();
        $form->add(new Element\Text('name'));
        $definition = F::form([
            F::field('content', 'intro', options: ['html' => 'Hi']),
            F::field('text', 'name', showLabel: false),
        ]);

        static::assertStringContainsString(
            '<div class="formbuilder__content">Hi</div></div><div class="formbuilder__field">'
                . '<div class="formbuilder__element"><input type="text" name="name" id="name"></div></div>',
            (new FormMarkup())->render($definition, $form),
        );
    }

    #[Test]
    public function rendersLabelsDescriptionsAndErrorsAroundTheInput(): void
    {
        $form = new Form();
        $form->add(new Element\Text('name'));
        $form->get('name')->setMessages([
            'isEmpty' => 'Required <field>',
            'nested'  => ['a', ['b'], 'c'],
            'odd'     => ['x' => 1],
        ]);
        $definition = F::form([F::field(
            'text',
            'name',
            label: 'Your name',
            required: true,
            description: 'As on <ID>',
        )]);

        $html = (new FormMarkup())->render($definition, $form);

        static::assertStringContainsString(
            '<div class="formbuilder__field"><label class="formbuilder__label formbuilder__label--required" for="name">Your name</label>'
                . '<div class="formbuilder__element"><input type="text" name="name" id="name"></div>'
                . '<ul class="formbuilder__errors"><li>Required &lt;field&gt;</li><li>a</li><li>c</li><li>1</li></ul>'
                . '<p class="formbuilder__description">As on &lt;ID&gt;</p></div>',
            $html,
        );
    }

    #[Test]
    public function rendersSanitizedContentBlocks(): void
    {
        $definition = F::form([
            F::field('content', 'intro', options: ['html' => '<p onclick="x">Hi<script>bad()</script></p>']),
            F::field('content', 'blank', options: ['html' => '  ']),
            F::field('content', 'odd', options: ['html' => ['<p>x</p>']]),
            F::field('content', 'missing'),
        ]);

        $html = (new FormMarkup())->render($definition, new Form());

        static::assertStringContainsString(
            '<div class="formbuilder__row"><div class="formbuilder__field"><div class="formbuilder__content"><p>Hi</p></div></div></div>',
            $html,
        );
    }

    #[Test]
    public function steppedFormKeepsANonScalarClassOut(): void
    {
        $form = new Form();
        $form->setAttribute('class', ['x']);
        $definition = F::formWithSections(
            [new SectionDefinition(
                id: null,
                key: 'only',
                groups: [new GroupDefinition(id: null)],
            )],
            layoutMode: FormDefinition::LAYOUT_STEPPED,
        );

        static::assertStringStartsWith(
            '<form method="POST" class="formbuilder__form--stepped" autocomplete="on" data-form-stepper="true">',
            (new FormMarkup())->render($definition, $form),
        );
    }

    #[Test]
    public function steppedLayoutRendersOneSectionPerStep(): void
    {
        $definition = F::formWithSections(
            [
                new SectionDefinition(
                    id: null,
                    key: 'your-details',
                    legend: '',
                    description: 'First',
                    groups: [new GroupDefinition(id: null)],
                ),
                new SectionDefinition(
                    id: null,
                    key: 'more',
                    legend: 'More info',
                    groups: [new GroupDefinition(id: null)],
                ),
            ],
            layoutMode: FormDefinition::LAYOUT_STEPPED,
        );
        $form = new Form();
        $form->add(new Element\File('cv'));

        static::assertSame(
            '<form method="POST" class="formbuilder__form formbuilder__form--stacked formbuilder__form--stepped" autocomplete="on" data-form-stepper="true" enctype="multipart/form-data">'
                . '<ol class="formbuilder__steps" role="tablist">'
                . '<li><button type="button" class="formbuilder__step-tab is-active" data-form-step-target="your-details"><span class="formbuilder__step-tab-index">1</span> Your details</button></li>'
                . '<li><button type="button" class="formbuilder__step-tab" data-form-step-target="more" disabled><span class="formbuilder__step-tab-index">2</span> More info</button></li>'
                . '</ol>'
                . '<section class="formbuilder__step is-active" data-form-step="your-details"><p class="formbuilder__step-description">First</p>'
                . '<fieldset class="formbuilder__panel"><p class="formbuilder__panel-empty"><em>No fields in this group yet.</em></p></fieldset>'
                . '<nav class="formbuilder__step-nav"><button type="button" class="btn btn--primary" data-form-step-next>Next</button></nav></section>'
                . '<section class="formbuilder__step" data-form-step="more" hidden><h2 class="formbuilder__step-title">More info</h2>'
                . '<fieldset class="formbuilder__panel"><p class="formbuilder__panel-empty"><em>No fields in this group yet.</em></p></fieldset>'
                . '<nav class="formbuilder__step-nav"><button type="button" class="btn" data-form-step-prev>Previous</button>'
                . '<div class="formbuilder__actions formbuilder__actions--left"></div></nav></section></form>',
            (new FormMarkup())->render($definition, $form),
        );
    }

    #[Test]
    public function steppedLayoutWithoutSectionsRendersAsSinglePage(): void
    {
        $html = (new FormMarkup())->render(
            F::formWithSections([], layoutMode: FormDefinition::LAYOUT_STEPPED),
            new Form(),
        );

        static::assertStringNotContainsString('data-form-stepper', $html);
    }

    #[Test]
    #[DataProvider('sectionHeadingProvider')]
    public function wrapsASectionOnlyWhenItHasAHeading(?string $legend, ?string $description, string $expected): void
    {
        $definition = F::formWithSections([
            new SectionDefinition(
                id: null,
                key: 's',
                legend: $legend,
                description: $description,
                groups: [new GroupDefinition(id: null)],
            ),
        ]);

        static::assertStringContainsString($expected, (new FormMarkup())->render($definition, new Form()));
    }

    #[Test]
    public function wrapsTheFormWithDefaultAttributesAndActions(): void
    {
        $form = new Form();
        $form->setAttributes(['method' => '', 'class' => null, 'data-x' => 'y']);

        $html = (new FormMarkup())->render(F::form(submitAlignment: 'center'), $form);

        static::assertSame(
            '<form method="post" class="formbuilder__form formbuilder__form--stacked" data-x="y" autocomplete="on">'
                . '<fieldset class="formbuilder__panel"><p class="formbuilder__panel-empty"><em>No fields in this group yet.</em></p></fieldset>'
                . '<div class="formbuilder__actions formbuilder__actions--center"></div></form>',
            $html,
        );
    }
}
