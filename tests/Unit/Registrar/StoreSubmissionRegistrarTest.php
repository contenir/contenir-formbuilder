<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Registrar;

use ArrayObject;
use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Registrar\StoreSubmissionRegistrar;
use Contenir\FormBuilder\Repository\EntryRepositoryInterface;
use Contenir\FormBuilder\Service\BuilderForm;
use Contenir\FormBuilder\Tests\TestAsset\Factory\FormDefinitionFactory as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SplObserver;
use SplSubject;

#[Group('unit')]
final class StoreSubmissionRegistrarTest extends TestCase
{
    /**
     * @return array<string, array{SplSubject}>
     */
    public static function nothingToStoreProvider(): array
    {
        return [
            'not a builder form' => [new class implements SplSubject {
                public function attach(SplObserver $observer): void {}

                public function detach(SplObserver $observer): void {}

                public function notify(): void {}
            }],
            'no registry'        => [new BuilderForm()],
            'no form definition' => [self::submission(['form' => 'contact', 'values' => []])],
            'an unsaved form'    => [self::submission(['form' => new FormDefinition(null, 'x', 'X'), 'values' => []])],
            'no values'          => [self::submission(['form' => F::form()])],
            'values not a list'  => [self::submission(['form' => F::form(), 'values' => 'name=Ann'])],
        ];
    }

    /**
     * @param array<string, mixed> $registry
     */
    private static function submission(array $registry): BuilderForm
    {
        $form           = new BuilderForm();
        $form->registry = new ArrayObject($registry);

        return $form;
    }

    #[Test]
    public function ignoresAContextValueOfTheWrongType(): void
    {
        $entries = $this->createMock(EntryRepositoryInterface::class);
        $entries->expects($this->once())->method('record')->with(1, [], 'complete', null, null, [])->willReturn(3);

        (new StoreSubmissionRegistrar($entries))->update(self::submission([
            'form'    => F::form(),
            'values'  => [],
            'context' => ['ip' => ['x'], 'user_id' => ['y'], 'meta' => 'z'],
        ]));
    }

    #[Test]
    public function storesACompleteEntryWithItsContextAndWritesItBack(): void
    {
        $entries = $this->createMock(EntryRepositoryInterface::class);
        $entries->expects($this->once())
            ->method('record')
            ->with(1, ['name' => 'Ann', '0' => 'x'], 'complete', '10.0.0.1', 7, ['user_agent' => 'Firefox'])
            ->willReturn(42);
        $form = self::submission([
            'form'    => F::form(),
            'values'  => ['name' => 'Ann', 0 => 'x'],
            'spam'    => false,
            'context' => ['ip' => '10.0.0.1', 'user_id' => '7', 'meta' => ['user_agent' => 'Firefox']],
        ]);

        (new StoreSubmissionRegistrar($entries))->update($form);

        static::assertSame([42, 'complete'], [
            $form->registry?->offsetGet('entry_id'),
            $form->registry?->offsetGet('entry_status'),
        ]);
    }

    #[Test]
    #[DataProvider('nothingToStoreProvider')]
    public function storesNothingWithoutASubmissionToStore(SplSubject $subject): void
    {
        $entries = $this->createMock(EntryRepositoryInterface::class);
        $entries->expects($this->never())->method('record');

        (new StoreSubmissionRegistrar($entries))->update($subject);
    }

    #[Test]
    public function storesSpamAsSpamWithoutAContext(): void
    {
        $entries = $this->createMock(EntryRepositoryInterface::class);
        $entries->expects($this->once())->method('record')->with(1, [], 'spam', null, null, [])->willReturn(3);
        $form = self::submission(['form' => F::form(), 'values' => [], 'spam' => true, 'context' => 'nonsense']);

        (new StoreSubmissionRegistrar($entries))->update($form);

        static::assertSame('spam', $form->registry?->offsetGet('entry_status'));
    }
}
