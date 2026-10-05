<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Integration\Service;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Definition\ValidatorDefinition;
use Contenir\FormBuilder\FieldType\FieldTypeRegistry;
use Contenir\FormBuilder\Service\FormBuilderService;
use Contenir\FormBuilder\Service\FormSubmissionService;
use Contenir\FormBuilder\Tests\TestAsset\Factory\FormDefinitionFactory as F;
use Contenir\FormBuilder\Tests\TestAsset\Observer\RecordingObserver;
use Contenir\FormBuilder\Tests\Trait\InMemorySessionTrait;
use Contenir\FormBuilder\Validator\ValidatorFactory;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
#[Group('service')]
final class FormSubmissionServiceTest extends TestCase
{
    use InMemorySessionTrait;

    private FormBuilderService $builder;

    /**
     * @return array<string, array{mixed}>
     */
    public static function honeypotProvider(): array
    {
        return [
            'filled honeypot'     => ['https://spam.example'],
            'non-string honeypot' => [['x']],
        ];
    }

    #[Test]
    public function confirmValidatorRejectsMismatchedFields(): void
    {
        $form = F::form([
            F::field('email', 'email'),
            F::field('email', 'email_again', validators: [new ValidatorDefinition('confirm', ['field' => 'email'])]),
        ]);

        $result = (new FormSubmissionService($this->builder))->submit(
            $form,
            $this->post($form, ['email' => 'a@example.com', 'email_again' => 'b@example.com']),
        );

        static::assertArrayHasKey('email_again', $result->errors);
    }

    #[Test]
    public function entryIdWrittenByAnObserverReachesLaterObserversAndTheResult(): void
    {
        $form    = $this->contactForm();
        $store   = new RecordingObserver(['entry_id' => 42, 'entry_status' => 'pending']);
        $notify  = new RecordingObserver();
        $service = new FormSubmissionService($this->builder);
        $service->attach($store);
        $service->attach($notify);

        $result = $service->submit($form, $this->post($form, ['name' => 'Ann']));

        static::assertSame(42, $result->entryId);
        static::assertSame([null, 'complete'], [
            $store->registries[0]['entry']['id'],
            $store->registries[0]['entry']['status'],
        ]);
        static::assertSame([42, 'pending'], [
            $notify->registries[0]['entry']['id'],
            $notify->registries[0]['entry']['status'],
        ]);
        static::assertSame($store->registries[0]['entry']['date'], $notify->registries[0]['entry']['date']);
    }

    #[Test]
    public function hiddenConditionalFieldIsNeitherValidatedNorReturned(): void
    {
        $form = F::form([
            F::field('select', 'contact_by', options: ['choices' => [['value' => 'email'], ['value' => 'phone']]]),
            F::field('text', 'phone', required: true, conditional: [
                'show_when' => ['all' => [['field' => 'contact_by', 'op' => 'equals', 'value' => 'phone']]],
            ]),
            F::field('text', 'note', conditional: [
                'show_when' => ['all' => [['field' => 'contact_by', 'op' => 'equals', 'value' => 'email']]],
            ]),
        ]);

        $result = (new FormSubmissionService($this->builder))->submit(
            $form,
            $this->post($form, ['contact_by' => 'email', 'phone' => 'leftover', 'note' => 'hi']),
        );

        static::assertTrue($result->valid);
        static::assertSame(['contact_by' => 'email', 'note' => 'hi'], $result->values);
    }

    #[Test]
    public function hiddenRequiredFieldDoesNotBlockTheSubmission(): void
    {
        $form = $this->conditionalForm();

        $result = (new FormSubmissionService($this->builder))->submit(
            $form,
            $this->post($form, ['contact_by' => 'email', 'phone' => '', 'note' => 'hi']),
        );

        static::assertTrue($result->valid);
    }

    #[Test]
    #[DataProvider('honeypotProvider')]
    public function honeypotSubmissionIsFlaggedAsSpamAndStillNotified(mixed $honeypot): void
    {
        $form     = $this->contactForm();
        $observer = new RecordingObserver();
        $service  = new FormSubmissionService($this->builder);
        $service->attach($observer);

        $result = $service->submit($form, [
            'name'                            => '',
            'email'                           => 'not-an-email',
            FormBuilderService::HONEYPOT_NAME => $honeypot,
            '_submit'                         => 'Send',
        ]);

        static::assertFalse($result->valid);
        static::assertTrue($result->isSpam);
        static::assertSame(['name' => '', 'email' => 'not-an-email'], $result->values);
        static::assertTrue($observer->registries[0]['spam']);
    }

    #[Test]
    public function invalidSpamSubmissionDropsHiddenFieldValues(): void
    {
        $form = $this->conditionalForm();

        $result = (new FormSubmissionService($this->builder))->submit(
            $form,
            $this->post($form, [
                'contact_by'                      => 'email',
                'phone'                           => 'leftover',
                'note'                            => '',
                FormBuilderService::HONEYPOT_NAME => 'bot',
            ]),
        );

        static::assertSame(['contact_by' => 'email', 'note' => ''], $result->values);
    }

    #[Test]
    public function invalidSpamSubmissionKeepsValuesForConditionalFieldsWithoutAnInput(): void
    {
        $form = F::form([
            F::field('text', 'name', required: true),
            F::field('content', 'intro', conditional: [
                'show_when' => ['all' => [['field' => 'name', 'op' => 'equals', 'value' => 'x']]],
            ]),
        ]);

        $result = (new FormSubmissionService($this->builder))->submit(
            $form,
            $this->post($form, ['name' => '', 'intro' => 'posted', FormBuilderService::HONEYPOT_NAME => 'bot']),
        );

        static::assertSame(['name' => '', 'intro' => 'posted'], $result->values);
    }

    #[Test]
    public function invalidSubmissionReturnsErrorsWithoutNotifying(): void
    {
        $form     = $this->contactForm();
        $observer = new RecordingObserver();
        $service  = new FormSubmissionService($this->builder);
        $service->attach($observer);

        $result = $service->submit($form, $this->post($form, ['name' => '']));

        static::assertFalse($result->valid);
        static::assertFalse($result->isSpam);
        static::assertSame([], $result->values);
        static::assertArrayHasKey('name', $result->errors);
        static::assertSame([], $observer->registries);
    }

    #[Test]
    public function missingCsrfTokenFailsValidation(): void
    {
        $result = (new FormSubmissionService($this->builder))->submit($this->contactForm(), ['name' => 'Ann']);

        static::assertArrayHasKey(FormBuilderService::CSRF_NAME, $result->errors);
    }

    #[Test]
    public function nonIntegerEntryIdIsIgnored(): void
    {
        $form    = $this->contactForm();
        $service = new FormSubmissionService($this->builder);
        $service->attach(new RecordingObserver(['entry_id' => '42']));

        static::assertNull($service->submit($form, $this->post($form, ['name' => 'Ann']))->entryId);
    }

    #[Test]
    public function shownConditionalFieldIsStillValidated(): void
    {
        $form = F::form([
            F::field('text', 'contact_by'),
            F::field('text', 'phone', required: true, conditional: [
                'show_when' => ['all' => [['field' => 'contact_by', 'op' => 'equals', 'value' => 'phone']]],
            ]),
        ]);

        $result = (new FormSubmissionService($this->builder))->submit(
            $form,
            $this->post($form, ['contact_by' => 'phone', 'phone' => '']),
        );

        static::assertArrayHasKey('phone', $result->errors);
    }

    #[Test]
    public function spamHoneypotValueIsNotEchoedBackIntoTheForm(): void
    {
        $form   = $this->contactForm();
        $post   = $this->post($form, ['name' => 'Ann', FormBuilderService::HONEYPOT_NAME => 'bot']);
        $result = (new FormSubmissionService($this->builder))->submit($form, $post);

        static::assertNull($result->form->get(FormBuilderService::HONEYPOT_NAME)->getValue());
    }

    /**
     * Laminas reads an integer key in a validation group as a fieldset name,
     * so the group must stay a list: the numeric field name "5" matches the
     * key the last element would keep if the group were not re-indexed.
     */
    #[Test]
    public function validationGroupStaysAListWhenAFieldIsHidden(): void
    {
        $form = F::form([
            F::field('text', 'extra', conditional: [
                'show_when' => ['all' => [['field' => 'note', 'op' => 'equals', 'value' => 'never']]],
            ]),
            F::field('text', 'note', required: true),
            F::field('text', '5'),
        ]);

        $result = (new FormSubmissionService($this->builder))->submit($form, $this->post($form, ['note' => 'hi']));

        static::assertTrue($result->valid);
    }

    #[Test]
    public function validSpamSubmissionUsesTheValidatedValues(): void
    {
        $form   = $this->contactForm();
        $post   = $this->post($form, ['name' => 'Ann', FormBuilderService::HONEYPOT_NAME => 'bot']);
        $result = (new FormSubmissionService($this->builder))->submit($form, $post);

        static::assertSame([false, true], [$result->valid, $result->isSpam]);
        static::assertSame(['name' => 'Ann', 'email' => null], $result->values);
    }

    #[Test]
    public function validSubmissionReturnsCleanValuesAndNotifiesObservers(): void
    {
        $form     = $this->contactForm();
        $observer = new RecordingObserver();
        $service  = new FormSubmissionService($this->builder);
        $service->attach($observer);

        $result = $service->submit($form, $this->post($form, ['name' => 'Ann']), [], ['ip' => '10.0.0.1']);

        static::assertTrue($result->valid);
        static::assertFalse($result->isSpam);
        static::assertSame(['name' => 'Ann', 'email' => null], $result->values);
        static::assertSame([], $result->errors);
        static::assertNull($result->entryId);
        static::assertCount(1, $observer->registries);
        $registry = $observer->registries[0];
        static::assertSame($form, $registry['form']);
        static::assertSame($result->values, $registry['values']);
        static::assertFalse($registry['spam']);
        static::assertSame(['ip' => '10.0.0.1'], $registry['context']);
        static::assertSame(null, $registry['entry']['id']);
        static::assertSame('10.0.0.1', $registry['entry']['ip']);
        static::assertSame('complete', $registry['entry']['status']);
        static::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $registry['entry']['date']);
    }

    #[Test]
    public function whitespaceHoneypotIsNotSpam(): void
    {
        $form   = $this->contactForm();
        $post   = $this->post($form, ['name' => 'Ann', FormBuilderService::HONEYPOT_NAME => " \t "]);
        $result = (new FormSubmissionService($this->builder))->submit($form, $post);

        static::assertSame([true, false], [$result->valid, $result->isSpam]);
    }

    #[Override]
    protected function setUp(): void
    {
        $this->setUpInMemorySession();
        $this->builder = new FormBuilderService(new FieldTypeRegistry(), new ValidatorFactory());
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->tearDownInMemorySession();
    }

    private function conditionalForm(): FormDefinition
    {
        return F::form([
            F::field('select', 'contact_by', options: ['choices' => [['value' => 'email'], ['value' => 'phone']]]),
            F::field('text', 'phone', required: true, conditional: [
                'show_when' => ['all' => [['field' => 'contact_by', 'op' => 'equals', 'value' => 'phone']]],
            ]),
            F::field('text', 'note', required: true),
        ]);
    }

    private function contactForm(): FormDefinition
    {
        return F::form([
            F::field('text', 'name', required: true),
            F::field('email', 'email'),
            F::field('content', 'intro', options: ['html' => '<p>Hi</p>']),
        ], submitLabel: 'Send');
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function post(FormDefinition $form, array $values): array
    {
        $csrf = $this->builder->build($form)->get(FormBuilderService::CSRF_NAME)->getValue();

        return [...$values, FormBuilderService::CSRF_NAME => $csrf, '_submit' => 'Send'];
    }
}
