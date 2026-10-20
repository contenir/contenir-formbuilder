<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Integration\Repository;

use Contenir\FormBuilder\Persistence\PersistenceException;
use Contenir\FormBuilder\Repository\EntryRepositoryInterface;
use Contenir\FormBuilder\Repository\PhpDbEntryRepository;
use Contenir\FormBuilder\Tests\TestAsset\Attempt;
use Contenir\FormBuilder\Tests\TestAsset\Clock\FrozenClock;
use Contenir\FormBuilder\Tests\TestAsset\Database\Seeder;
use Contenir\FormBuilder\Tests\TestAsset\Database\TestDatabase;
use Contenir\FormBuilder\Tests\Trait\TestDatabaseTrait;
use DateTimeImmutable;
use PhpDb\Adapter\Profiler\Profiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_keys;
use function array_map;
use function count;
use function ksort;

#[Group('integration')]
#[Group('repository')]
final class PhpDbEntryRepositoryTest extends TestCase
{
    use TestDatabaseTrait;

    private Seeder $seed;

    private PhpDbEntryRepository $entries;

    private int $formId;

    /**
     * @return array<string, array{list<string>|null, string|null, string|null, list<string>}>
     */
    public static function filterProvider(): array
    {
        return [
            'everything'                    => [null, null, null, ['d', 'c', 'b', 'a']],
            'no statuses is every status'   => [[], null, null, ['d', 'c', 'b', 'a']],
            'by status'                     => [['spam', 'archive'], null, null, ['c', 'b']],
            'since a date, inclusive'       => [null, '2026-02-01 00:00:00', null, ['d', 'c', 'b']],
            'an empty date is no date'      => [null, '', null, ['d', 'c', 'b', 'a']],
            'text searches the address'     => [null, null, '192.168', ['c']],
            'digits search the id'          => [null, null, '4', ['d']],
            'digits search the address too' => [null, null, '12', ['b']],
            'an empty search is no search'  => [null, null, '', ['d', 'c', 'b', 'a']],
            'all together'                  => [['complete'], '2026-01-15 00:00:00', '10.', ['d']],
        ];
    }

    #[Test]
    public function aFailedRecordLeavesNoEntry(): void
    {
        $failure = Attempt::of(fn(): int => $this->entries->record(999, ['name' => 'x'], 'complete', null, null));

        static::assertSame([PersistenceException::class, [], []], [
            $failure,
            $this->seed->rows('form_entry', ['form_entry_id']),
            $this->seed->rows('form_entry_value', ['form_entry_value_id']),
        ]);
    }

    #[Test]
    public function aRetentionPeriodBelowOneDayCountsAsOneDay(): void
    {
        $dayOld = $this->entry(['submitted_at' => '2026-10-19 09:29:59']);
        $this->entry(['submitted_at' => '2026-10-19 09:30:01']);

        static::assertSame([$dayOld], $this->ids($this->entries->findExpired($this->formId, 0)));
    }

    #[Test]
    public function countsEachStatusUnderTheSameFilterButEveryStatus(): void
    {
        $this->filterFixture();
        $this->entry(['form_id' => $this->otherForm(), 'status' => 'complete']);

        static::assertSame(
            [
                ['archive' => 1, 'complete' => 2, 'spam' => 1],
                ['archive' => 1, 'complete' => 1, 'spam' => 1],
                ['archive' => 1],
            ],
            [
                $this->sorted($this->entries->countByStatus($this->formId)),
                $this->sorted($this->entries->countByStatus($this->formId, '2026-02-01 00:00:00')),
                $this->sorted($this->entries->countByStatus($this->formId, search: '192')),
            ],
        );
    }

    #[Test]
    public function deletesAnEntrysValuesWhereForeignKeysAreOff(): void
    {
        $id = $this->entry();
        $this->value($id, 'a');
        TestDatabase::withoutForeignKeys($this->adapter);

        $this->entries->delete($id);

        static::assertSame([], $this->seed->rows('form_entry_value', ['field_name']));
    }

    #[Test]
    public function deletesAnEntryWithItsValues(): void
    {
        $id    = $this->entry();
        $other = $this->entry();
        $this->value($id, 'a');
        $this->value($other, 'b');

        $this->entries->delete($id);

        static::assertSame(
            [[['form_entry_id' => (string) $other]], [['field_name' => 'b']]],
            [$this->seed->rows('form_entry', ['form_entry_id']), $this->seed->rows('form_entry_value', ['field_name'])],
        );
    }

    #[Test]
    public function fetchesAnEntrysValuesInOrder(): void
    {
        $id = $this->entry();
        $this->value($id, 'b');
        $this->value($id, 'a');
        $this->value($this->entry(), 'other');

        static::assertSame(['b', 'a'], array_column($this->entries->fetchValues($id), 'field_name'));
    }

    #[Test]
    public function fetchesNoValuesForNoEntriesWithoutAQuery(): void
    {
        $this->value($this->entry(), 'a');
        $profiler = new Profiler();
        $this->adapter->setProfiler($profiler);

        static::assertSame([[], []], [$this->entries->fetchValuesForEntries([]), $profiler->getProfiles()]);
    }

    #[Test]
    public function fetchesTheValuesOfSeveralEntriesKeyedByEntry(): void
    {
        $first  = $this->entry();
        $second = $this->entry();
        $empty  = $this->entry();
        $this->value($this->entry(), 'not asked for');
        $this->value($first, 'a');
        $this->value($second, 'b');
        $this->value($first, 'c');

        $values = $this->entries->fetchValuesForEntries([$first, $second, $empty]);

        static::assertSame(
            [[$first, $second], ['a', 'c'], ['b']],
            [
                array_keys($values),
                array_column($values[$first], 'field_name'),
                array_column($values[$second], 'field_name'),
            ],
        );
    }

    /**
     * @param list<string>|null $statuses
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('filterProvider')]
    public function filtersTheList(?array $statuses, ?string $since, ?string $search, array $expected): void
    {
        $names = $this->filterFixture();

        static::assertSame(
            $expected,
            array_map(
                static fn(array $entry): string => $names[(int) $entry['form_entry_id']],
                $this->entries->listEntries($this->formId, $statuses, sinceDate: $since, search: $search),
            ),
        );
    }

    #[Test]
    public function findsAnEntry(): void
    {
        $id = $this->entry(['ip' => '10.0.0.9']);

        static::assertSame(
            ['10.0.0.9', null],
            [$this->entries->findEntry($id)['ip'] ?? null, $this->entries->findEntry($id + 1)],
        );
    }

    #[Test]
    public function findsEntriesOlderThanTheRetentionPeriodByTheClock(): void
    {
        $expired = $this->entry(['submitted_at' => '2026-10-10 09:29:59']);
        $this->entry(['submitted_at' => '2026-01-01 00:00:00', 'status' => 'redacted']);
        $this->entry(['submitted_at' => '2026-10-10 09:30:00']);
        $spam = $this->entry(['submitted_at' => '2026-01-01 00:00:00', 'status' => 'spam']);
        $this->entry(['submitted_at' => '2026-01-01 00:00:00', 'form_id' => $this->otherForm()]);

        static::assertSame([$expired, $spam], $this->ids($this->entries->findExpired($this->formId, 10)));
    }

    #[Test]
    public function listsABatchNewestFirstWhicheverWayTheDatabaseReadsIt(): void
    {
        $older = $this->entry();
        $newer = $this->entry();
        TestDatabase::readUnorderedSelectsForwards($this->adapter);

        static::assertSame([$newer, $older], $this->ids($this->entries->listEntriesBefore($this->formId, null, 2)));
    }

    #[Test]
    public function listsABatchOfEntriesNewestFirstBeforeAnId(): void
    {
        $ids = [];
        foreach (['complete', 'spam', 'complete', 'archive', 'complete'] as $status) {
            $ids[] = $this->entry(['status' => $status]);
        }
        $this->entry(['form_id' => $this->otherForm()]);

        static::assertSame(
            [
                [$ids[4], $ids[3]],
                [$ids[2], $ids[1]],
                [$ids[4], $ids[2], $ids[0]],
                [$ids[3], $ids[2], $ids[0]],
            ],
            [
                $this->ids($this->entries->listEntriesBefore($this->formId, null, 2)),
                $this->ids($this->entries->listEntriesBefore($this->formId, $ids[3], 2)),
                $this->ids($this->entries->listEntriesBefore($this->formId, null, 10, ['spam', 'archive'])),
                $this->ids($this->entries->listEntriesBefore($this->formId, $ids[4], 10, ['spam'])),
            ],
        );
    }

    #[Test]
    public function listsAHundredEntriesUnlessToldOtherwise(): void
    {
        for ($entry = 0; $entry < 101; $entry++) {
            $this->entry();
        }

        static::assertSame(100, count($this->entries->listEntries($this->formId)));
    }

    #[Test]
    public function listsAPageOfEntriesNewestFirst(): void
    {
        $old  = $this->entry(['submitted_at' => '2026-01-01 00:00:00']);
        $new  = $this->entry(['submitted_at' => '2026-03-01 00:00:00']);
        $tieA = $this->entry(['submitted_at' => '2026-02-01 00:00:00']);
        $tieB = $this->entry(['submitted_at' => '2026-02-01 00:00:00']);
        $this->entry(['form_id' => $this->otherForm()]);

        static::assertSame(
            [[$new, $tieB, $tieA, $old], [$tieA], [$new]],
            [
                $this->ids($this->entries->listEntries($this->formId)),
                $this->ids($this->entries->listEntries($this->formId, limit: 1, offset: 2)),
                $this->ids($this->entries->listEntries($this->formId, limit: 1)),
            ],
        );
    }

    #[Test]
    public function marksAnEntryNotifiedKeepingItsMeta(): void
    {
        $id   = $this->entry(['meta_json' => '{"user_agent":"Firefox/1"}']);
        $bare = $this->entry();

        $this->entries->markNotified($id);
        $this->entries->markNotified($bare);
        $this->entries->markNotified($bare + 99);

        static::assertSame(
            [
                ['meta_json' => '{"user_agent":"Firefox/1","notified_at":"2026-10-20 09:30:00"}'],
                ['meta_json' => '{"notified_at":"2026-10-20 09:30:00"}'],
            ],
            $this->seed->rows('form_entry', ['meta_json']),
        );
    }

    #[Test]
    public function recordsAnEntryWithItsValuesAtTheClocksTime(): void
    {
        $id = $this->entries->record(
            $this->formId,
            ['name' => 'Zoë', 'age' => 42, 'topics' => ['a/b', 'c'], 'none' => [], 'file' => null, 'agree' => false],
            EntryRepositoryInterface::STATUS_COMPLETE,
            '10.0.0.1',
            7,
            ['user_agent' => 'Firefox/1'],
        );

        static::assertSame(
            [
                [[
                    'form_entry_id' => (string) $id,
                    'form_id'       => (string) $this->formId,
                    'submitted_at'  => '2026-10-20 09:30:00',
                    'ip'            => '10.0.0.1',
                    'user_id'       => '7',
                    'status'        => 'complete',
                    'meta_json'     => '{"user_agent":"Firefox/1"}',
                ]],
                [
                    ['field_name' => 'name', 'form_field_id' => null, 'value_text' => 'Zoë', 'value_json' => null],
                    ['field_name' => 'age', 'form_field_id' => null, 'value_text' => '42', 'value_json' => null],
                    [
                        'field_name'    => 'topics',
                        'form_field_id' => null,
                        'value_text'    => null,
                        'value_json'    => '["a/b","c"]',
                    ],
                    ['field_name' => 'none', 'form_field_id' => null, 'value_text' => null, 'value_json' => '[]'],
                    ['field_name' => 'file', 'form_field_id' => null, 'value_text' => null, 'value_json' => null],
                    ['field_name' => 'agree', 'form_field_id' => null, 'value_text' => '', 'value_json' => null],
                ],
            ],
            [
                $this->seed->rows('form_entry', [
                    'form_entry_id',
                    'form_id',
                    'submitted_at',
                    'ip',
                    'user_id',
                    'status',
                    'meta_json',
                ]),
                $this->seed->rows('form_entry_value', ['field_name', 'form_field_id', 'value_text', 'value_json']),
            ],
        );
    }

    #[Test]
    public function recordsAnEntryWithNoMetaAsNull(): void
    {
        $this->entries->record($this->formId, [], 'spam', null, null);

        static::assertSame(
            [['ip' => null, 'user_id' => null, 'status' => 'spam', 'meta_json' => null]],
            $this->seed->rows('form_entry', ['ip', 'user_id', 'status', 'meta_json']),
        );
    }

    #[Test]
    public function redactsAnEntryKeepingItsRow(): void
    {
        $id    = $this->entry(['ip' => '10.0.0.1', 'user_id' => 3, 'meta_json' => '{"a":1}']);
        $other = $this->entry(['ip' => '10.0.0.2']);
        $this->value($id, 'a');
        $this->value($other, 'b');

        $this->entries->redact($id);

        static::assertSame(
            [
                [
                    [
                        'form_entry_id' => (string) $id,
                        'ip'            => null,
                        'user_id'       => '3',
                        'status'        => 'redacted',
                        'meta_json'     => null,
                    ],
                    [
                        'form_entry_id' => (string) $other,
                        'ip'            => '10.0.0.2',
                        'user_id'       => null,
                        'status'        => 'complete',
                        'meta_json'     => null,
                    ],
                ],
                [['field_name' => 'b']],
            ],
            [
                $this->seed->rows('form_entry', ['form_entry_id', 'ip', 'user_id', 'status', 'meta_json']),
                $this->seed->rows('form_entry_value', ['field_name']),
            ],
        );
    }

    #[Test]
    public function setsAnEntrysStatus(): void
    {
        $id    = $this->entry();
        $other = $this->entry();

        $this->entries->setStatus($id, 'archive');

        static::assertSame(
            [['status' => 'archive'], ['status' => 'complete']],
            [
                ...$this->seed->rows('form_entry', ['status'], ['form_entry_id' => $id]),
                ...$this->seed->rows('form_entry', ['status'], ['form_entry_id' => $other]),
            ],
        );
    }

    #[Test]
    public function tellsWhetherAnEntryWasNotified(): void
    {
        static::assertSame(
            [true, false, false, false],
            [
                $this->entries->wasNotified(['meta_json' => '{"notified_at":"2026-10-20 09:30:00"}']),
                $this->entries->wasNotified(['meta_json' => '{"user_agent":"x"}']),
                $this->entries->wasNotified(['meta_json' => null]),
                $this->entries->wasNotified([]),
            ],
        );
    }

    protected function setUp(): void
    {
        $this->setUpDatabase();
        $this->seed    = new Seeder($this->adapter);
        $this->formId  = $this->seed->insert('form', ['slug' => 'contact', 'title' => 'Contact']);
        $this->entries = new PhpDbEntryRepository(
            $this->adapter,
            new FrozenClock(new DateTimeImmutable('2026-10-20 09:30:00')),
        );
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabase();
    }

    /**
     * @param array<string, scalar|null> $values
     */
    private function entry(array $values = []): int
    {
        return $this->seed->insert('form_entry', [
            ...['form_id' => $this->formId, 'submitted_at' => '2026-10-01 00:00:00', 'status' => 'complete'],
            ...$values,
        ]);
    }

    /**
     * Four entries for the filter cases, by name.
     *
     * @return array<int, string>
     */
    private function filterFixture(): array
    {
        return [
            $this->entry([
                'submitted_at' => '2026-01-01 00:00:00',
                'ip'           => '10.0.0.5',
            ]) => 'a',
            $this->entry([
                'submitted_at' => '2026-02-01 00:00:00',
                'ip'           => '10.0.0.12',
                'status'       => 'spam',
            ]) => 'b',
            $this->entry([
                'submitted_at' => '2026-03-01 00:00:00',
                'ip'           => '192.168.0.1',
                'status'       => 'archive',
            ]) => 'c',
            $this->entry([
                'submitted_at' => '2026-04-01 00:00:00',
                'ip'           => '10.0.0.7',
            ]) => 'd',
        ];
    }

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return list<int>
     */
    private function ids(array $entries): array
    {
        return array_map(static fn(array $entry): int => (int) $entry['form_entry_id'], $entries);
    }

    private function otherForm(): int
    {
        return $this->seed->insert('form', [
            'slug'  => 'other-' . count($this->seed->rows('form', ['form_id'])),
            'title' => 'Other',
        ]);
    }

    /**
     * @param array<string, int> $counts
     *
     * @return array<string, int>
     */
    private function sorted(array $counts): array
    {
        ksort($counts);

        return $counts;
    }

    private function value(int $entryId, string $name): void
    {
        $this->seed->insert('form_entry_value', [
            'form_entry_id' => $entryId,
            'field_name'    => $name,
            'value_text'    => $name,
        ]);
    }
}
