<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Integration\Redaction;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Loader\PhpDbFormLoader;
use Contenir\FormBuilder\Redaction\EntryFilesInterface;
use Contenir\FormBuilder\Redaction\ExpiredEntryRedactor;
use Contenir\FormBuilder\Repository\PhpDbEntryRepository;
use Contenir\FormBuilder\Tests\TestAsset\Clock\FrozenClock;
use Contenir\FormBuilder\Tests\TestAsset\Database\Seeder;
use Contenir\FormBuilder\Tests\Trait\TestDatabaseTrait;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
#[Group('service')]
final class ExpiredEntryRedactorTest extends TestCase
{
    use TestDatabaseTrait;

    private Seeder $seed;

    #[Test]
    public function keepsAnEntryWhoseFilesCouldNotBeDeleted(): void
    {
        $formId = $this->form('daily', 1);
        $stuck  = $this->entry($formId, '2026-10-01 00:00:00', 'Ann');
        $done   = $this->entry($formId, '2026-10-02 00:00:00', 'Bo');
        $files  = new class($stuck) implements EntryFilesInterface {
            /** @var list<array{int, list<array<string, mixed>>}> */
            public array $asked = [];

            public function __construct(
                private readonly int $stuck,
            ) {}

            public function delete(FormDefinition $form, int $entryId, array $values): bool
            {
                $this->asked[] = [$entryId, $values];

                return $entryId !== $this->stuck;
            }
        };

        $report = $this->redactor($files)->redactExpired();

        static::assertSame(
            [
                [1, 1, 1],
                [$stuck, $done],
                ['Ann', 'Bo'],
                [['status' => 'complete'], ['status' => 'redacted']],
            ],
            [
                [$report->entries, $report->forms, $report->failed],
                [$files->asked[0][0], $files->asked[1][0]],
                [$files->asked[0][1][0]['value_text'] ?? null, $files->asked[1][1][0]['value_text'] ?? null],
                $this->seed->rows('form_entry', ['status']),
            ],
        );
    }

    #[Test]
    public function keepsAnEntryWithFilesWhenThereIsNoFileStorage(): void
    {
        $formId  = $this->form('daily', 1);
        $fieldId = $this->seed->insert('form_row', [
            'form_group_id' => $this->seed->insert('form_group', [
                'form_section_id' => $this->seed->insert('form_section', ['form_id' => $formId, 'key' => 'main']),
            ]),
        ]);
        $this->seed->insert('form_field', ['form_row_id' => $fieldId, 'type' => 'file', 'name' => 'cv']);
        $entry = $this->entry($formId, '2026-10-01 00:00:00', 'Ann');
        $this->seed->insert('form_entry_value', [
            'form_entry_id' => $entry,
            'field_name'    => 'cv',
            'value_text'    => 'forms/cv.pdf',
        ]);

        $report = $this->redactor()->redactExpired();

        static::assertSame([0, 0, 1], [$report->entries, $report->forms, $report->failed]);
    }

    #[Test]
    public function redactsEntriesPastEachFormsRetentionPeriod(): void
    {
        $kept    = $this->form('kept', null);
        $daily   = $this->form('daily', 1);
        $monthly = $this->form('monthly', 30);
        $zero    = $this->form('zero', 0);
        $old     = $this->entry($daily, '2026-10-18 00:00:00', 'Ann');
        $recent  = $this->entry($daily, '2026-10-20 00:00:00', 'Bo');
        $month   = $this->entry($monthly, '2026-09-01 00:00:00', 'Cy');
        $this->entry($monthly, '2026-10-01 00:00:00', 'Di');
        $this->entry($kept, '2020-01-01 00:00:00', 'Ed');
        $this->entry($zero, '2020-01-01 00:00:00', 'Fi');

        $report = $this->redactor()->redactExpired();

        static::assertSame(
            [
                [2, 2, 0],
                [
                    ['form_entry_id' => (string) $old, 'status' => 'redacted', 'ip' => null],
                    ['form_entry_id' => (string) $recent, 'status' => 'complete', 'ip' => '10.0.0.1'],
                    ['form_entry_id' => (string) $month, 'status' => 'redacted', 'ip' => null],
                ],
                [['value_text' => 'Bo'], ['value_text' => 'Di'], ['value_text' => 'Ed'], ['value_text' => 'Fi']],
            ],
            [
                [$report->entries, $report->forms, $report->failed],
                [
                    ...$this->seed->rows('form_entry', ['form_entry_id', 'status', 'ip'], ['form_id' => $daily]),
                    ...$this->seed->rows('form_entry', ['form_entry_id', 'status', 'ip'], ['form_entry_id' => $month]),
                ],
                $this->seed->rows('form_entry_value', ['value_text']),
            ],
        );
    }

    protected function setUp(): void
    {
        $this->setUpDatabase();
        $this->seed = new Seeder($this->adapter);
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabase();
    }

    private function entry(int $formId, string $submittedAt, string $name): int
    {
        $id = $this->seed->insert('form_entry', [
            'form_id'      => $formId,
            'submitted_at' => $submittedAt,
            'ip'           => '10.0.0.1',
            'status'       => 'complete',
        ]);
        $this->seed->insert('form_entry_value', [
            'form_entry_id' => $id,
            'field_name'    => 'name',
            'value_text'    => $name,
        ]);

        return $id;
    }

    private function form(string $slug, ?int $retentionDays): int
    {
        return $this->seed->insert('form', ['slug' => $slug, 'title' => $slug, 'retention_days' => $retentionDays]);
    }

    private function redactor(?EntryFilesInterface $files = null): ExpiredEntryRedactor
    {
        $forms   = new PhpDbFormLoader($this->adapter);
        $entries = new PhpDbEntryRepository(
            $this->adapter,
            new FrozenClock(new DateTimeImmutable('2026-10-20 09:30:00')),
        );

        return null === $files
            ? new ExpiredEntryRedactor($forms, $entries)
            : new ExpiredEntryRedactor($forms, $entries, $files);
    }
}
