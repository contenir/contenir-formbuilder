<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Service;

use ArrayObject;
use Contenir\FormBuilder\Service\FormBuilderInterface;
use Contenir\FormBuilder\Service\FormSubmissionService;
use Contenir\FormBuilder\Tests\TestAsset\Factory\FormDefinitionFactory as F;
use Contenir\FormBuilder\Tests\TestAsset\Observer\RecordingObserver;
use Laminas\Form\Element\Text;
use Laminas\Form\Form;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Covers builders that return a plain Laminas form rather than a BuilderForm,
 * which another FormBuilderInterface implementation may do.
 */
#[Group('unit')]
final class FormSubmissionServiceTest extends TestCase
{
    #[Test]
    public function boundObjectDataFallsBackToThePostedValues(): void
    {
        $form = $this->plainForm();
        $form->bind(new ArrayObject());

        $result = (new FormSubmissionService($this->builderReturning($form)))->submit(
            F::form([F::field('text', 'name')]),
            ['name' => 'Ann', '_submit' => 'Send'],
        );

        static::assertSame(['name' => 'Ann'], $result->values);
    }

    #[Test]
    public function plainFormIsValidatedWithoutNotifyingObservers(): void
    {
        $observer = new RecordingObserver();
        $service  = new FormSubmissionService($this->builderReturning($this->plainForm()));
        $service->attach($observer);

        $result = $service->submit(F::form([F::field('text', 'name')]), ['name' => 'Ann']);

        static::assertSame([true, ['name' => 'Ann'], null], [$result->valid, $result->values, $result->entryId]);
        static::assertSame([], $observer->subjects);
    }

    private function builderReturning(Form $form): FormBuilderInterface
    {
        $builder = $this->createStub(FormBuilderInterface::class);
        $builder->method('build')->willReturn($form);

        return $builder;
    }

    private function plainForm(): Form
    {
        $form = new Form();
        $form->add(new Text('name'));

        return $form;
    }
}
