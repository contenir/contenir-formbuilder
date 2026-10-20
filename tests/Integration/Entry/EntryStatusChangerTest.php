<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Integration\Entry;

use Contenir\FormBuilder\Entry\EntryStatusChanger;
use Contenir\FormBuilder\Repository\PhpDbEntryRepository;
use Contenir\FormBuilder\Tests\TestAsset\Clock\FrozenClock;
use Contenir\FormBuilder\Tests\TestAsset\Database\Seeder;
use Contenir\FormBuilder\Tests\TestAsset\Factory\FormDefinitionFactory as F;
use Contenir\FormBuilder\Tests\TestAsset\Observer\RecordingObserver;
use Contenir\FormBuilder\Tests\Trait\TestDatabaseTrait;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;

#[Group('integration')]
#[Group('service')]
final class EntryStatusChangerTest extends TestCase
{
    use TestDatabaseTrait;

    private Seeder $seed;

    private RecordingObserver $registrar;

    private EntryStatusChanger $changer;

    private int $formId;

    /**
     * @return array<string, array{string, string}>
     */
    public static function noSendProvider(): array
    {
        return [
            'spam archived'          => ['spam', 'archive'],
            'archived made complete' => ['archive', 'complete'],
            'pending made complete'  => ['pending', 'complete'],
            'unchanged spam'         => ['spam', 'spam'],
            'unchanged complete'     => ['complete', 'complete'],
        ];
    }

    #[Test]
    public function doesNothingForAnUnknownEntry(): void
    {
        static::assertFalse($this->changer->change(F::form(), 999, 'complete'));
    }

    #[Test]
    public function marksACompleteEntryNotifiedWhenItLeavesComplete(): void
    {
        $id = $this->entry('complete');

        $left = $this->changer->change(F::form(), $id, 'spam');
        $sent = $this->changer->change(F::form(), $id, 'complete');

        static::assertSame([false, false, [], [['status' => 'complete']]], [
            $left,
            $sent,
            $this->registrar->registries,
            $this->seed->rows('form_entry', ['status']),
        ]);
    }

    #[Test]
    #[DataProvider('noSendProvider')]
    public function movesOtherwiseWithoutSendingOrMarking(string $from, string $to): void
    {
        $id = $this->entry($from);

        $sent = $this->changer->change(F::form(), $id, $to);

        static::assertSame([false, [], [['status' => $to, 'meta_json' => null]]], [
            $sent,
            $this->registrar->registries,
            $this->seed->rows('form_entry', ['status', 'meta_json']),
        ]);
    }

    #[Test]
    public function neverSendsTheSameEntryTwice(): void
    {
        $id = $this->entry('spam');

        $first = $this->changer->change(F::form(), $id, 'complete');
        $this->changer->change(F::form(), $id, 'spam');
        $second = $this->changer->change(F::form(), $id, 'complete');

        static::assertSame([true, false, 1], [$first, $second, count($this->registrar->registries)]);
    }

    #[Test]
    public function sendsOnSpamMarkedCompleteWithItsSubmittedValues(): void
    {
        $id = $this->entry('spam');
        $this->seed->insert('form_entry_value', [
            'form_entry_id' => $id,
            'field_name'    => 'name',
            'value_text'    => 'Ann',
        ]);
        $this->seed->insert('form_entry_value', [
            'form_entry_id' => $id,
            'field_name'    => 'topics',
            'value_json'    => '["a","b"]',
        ]);
        $this->seed->insert('form_entry_value', ['form_entry_id' => $id, 'field_name' => 'file']);
        $form = F::form();

        $sent = $this->changer->change($form, $id, 'complete');

        static::assertSame(
            [
                true,
                [
                    'form'         => $form,
                    'values'       => ['name' => 'Ann', 'topics' => ['a', 'b'], 'file' => null],
                    'spam'         => false,
                    'entry_id'     => $id,
                    'entry_status' => 'complete',
                    'entry'        => [
                        'id'     => $id,
                        'date'   => '2026-10-01 08:00:00',
                        'ip'     => '10.0.0.1',
                        'status' => 'complete',
                    ],
                ],
                [['status' => 'complete', 'meta_json' => '{"notified_at":"2026-10-20 09:30:00"}']],
            ],
            [
                $sent,
                $this->registrar->registries[0] ?? null,
                $this->seed->rows('form_entry', ['status', 'meta_json']),
            ],
        );
    }

    protected function setUp(): void
    {
        $this->setUpDatabase();
        $this->seed      = new Seeder($this->adapter);
        $this->formId    = $this->seed->insert('form', ['slug' => 'contact', 'title' => 'Contact']);
        $this->registrar = new RecordingObserver();
        $this->changer   = new EntryStatusChanger(
            new PhpDbEntryRepository($this->adapter, new FrozenClock(new DateTimeImmutable('2026-10-20 09:30:00'))),
            [$this->registrar],
        );
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabase();
    }

    private function entry(string $status): int
    {
        return $this->seed->insert('form_entry', [
            'form_id'      => $this->formId,
            'submitted_at' => '2026-10-01 08:00:00',
            'ip'           => '10.0.0.1',
            'status'       => $status,
        ]);
    }
}
