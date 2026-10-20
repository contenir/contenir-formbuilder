<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Integration\Export;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Export\EntryCsvWriter;
use Contenir\FormBuilder\Repository\PhpDbEntryRepository;
use Contenir\FormBuilder\Tests\TestAsset\Database\Seeder;
use Contenir\FormBuilder\Tests\TestAsset\Factory\FormDefinitionFactory as F;
use Contenir\FormBuilder\Tests\Trait\TestDatabaseTrait;
use PhpDb\Adapter\Profiler\Profiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;
use function explode;
use function fopen;
use function rewind;
use function str_getcsv;
use function stream_get_contents;
use function trim;

#[Group('integration')]
#[Group('export')]
final class EntryCsvWriterTest extends TestCase
{
    use TestDatabaseTrait;

    private Seeder $seed;

    private FormDefinition $form;

    /**
     * @return array<string, array{string, string}>
     */
    public static function formulaProvider(): array
    {
        return [
            'equals'          => ['=HYPERLINK("x")', "\"'=HYPERLINK(\"\"x\"\")\""],
            'plus'            => ['+1', "'+1"],
            'minus'           => ['-1', "'-1"],
            'at'              => ['@SUM(A1)', "'@SUM(A1)"],
            'tab'             => ["\tx", "\"'\tx\""],
            'carriage return' => ["\rx", "\"'\rx\""],
            'in the middle'   => ['a=b', 'a=b'],
        ];
    }

    /**
     * The first value of each line.
     *
     * @return list<string>
     */
    private static function names(string $csv): array
    {
        $names = [];
        foreach (explode("\n", trim($csv)) as $index => $line) {
            $cells   = str_getcsv($line, escape: '\\');
            $names[] = 0 === $index ? $line : (string) $cells[3];
        }

        return $names;
    }

    #[Test]
    #[DataProvider('formulaProvider')]
    public function neutralisesACellASpreadsheetWouldRunAsAFormula(string $value, string $cell): void
    {
        $id = $this->entry('2026-10-01 08:00:00', ['name' => $value]);

        static::assertSame(
            "form_entry_id,submitted_at,status,name,topics\n{$id},\"2026-10-01 08:00:00\",complete,{$cell},\n",
            $this->export(new EntryCsvWriter($this->repository())),
        );
    }

    #[Test]
    public function readsFiveHundredEntriesABatchUnlessToldOtherwise(): void
    {
        for ($entry = 0; $entry < 500; $entry++) {
            $this->seed->insert('form_entry', ['form_id' => $this->form->id, 'status' => 'complete']);
        }
        $profiler = new Profiler();
        $this->adapter->setProfiler($profiler);

        $this->export(new EntryCsvWriter($this->repository()));

        static::assertCount(3, $profiler->getProfiles());
    }

    #[Test]
    public function readsInBatchesWithTheValuesOfEachBatchInOneQuery(): void
    {
        foreach (['01', '02', '03', '04', '05'] as $day) {
            $this->entry("2026-10-{$day} 08:00:00", ['name' => $day]);
        }
        $profiler = new Profiler();
        $this->adapter->setProfiler($profiler);

        $csv = $this->export(new EntryCsvWriter($this->repository(), batchSize: 2));

        static::assertSame(
            [6, ['form_entry_id,submitted_at,status,name,topics', '05', '04', '03', '02', '01']],
            [count($profiler->getProfiles()), self::names($csv)],
        );
    }

    #[Test]
    public function writesEveryEntryNewestFirstWithAColumnPerField(): void
    {
        $first  = $this->entry('2026-10-01 08:00:00', ['name' => 'Ann', 'topics' => null], ['topics' => '["a","b"]']);
        $second = $this->entry('2026-10-02 08:00:00', ['name' => 'Bo "B", Jr']);
        $this->entry('2026-10-03 08:00:00', ['name' => 'Bot'], status: 'spam');
        $other = $this->seed->insert('form', ['slug' => 'other', 'title' => 'Other']);
        $this->seed->insert('form_entry', ['form_id' => $other, 'status' => 'complete']);

        static::assertSame(
            "form_entry_id,submitted_at,status,name,topics\n"
                . "{$second},\"2026-10-02 08:00:00\",complete,\"Bo \"\"B\"\", Jr\",\n"
                . "{$first},\"2026-10-01 08:00:00\",complete,Ann,\"[\"\"a\"\",\"\"b\"\"]\"\n",
            $this->export(new EntryCsvWriter($this->repository())),
        );
    }

    #[Test]
    public function writesTheHeaderAloneForAFormWithNoEntries(): void
    {
        static::assertSame(
            "form_entry_id,submitted_at,status,name,topics\n",
            $this->export(new EntryCsvWriter($this->repository())),
        );
    }

    #[Test]
    public function writesTheStatusesAskedFor(): void
    {
        $spam = $this->entry('2026-10-03 08:00:00', ['name' => 'Bot'], status: 'spam');
        $this->entry('2026-10-02 08:00:00', ['name' => 'Ann'], status: 'archive');

        static::assertSame(
            "form_entry_id,submitted_at,status,name,topics\n{$spam},\"2026-10-03 08:00:00\",spam,Bot,\n",
            $this->export(new EntryCsvWriter($this->repository()), ['archive']),
        );
    }

    protected function setUp(): void
    {
        $this->setUpDatabase();
        $this->seed = new Seeder($this->adapter);
        $formId     = $this->seed->insert('form', ['slug' => 'contact', 'title' => 'Contact']);
        $this->form = new FormDefinition(
            $formId,
            'contact',
            'Contact',
            sections: [F::section('main', [F::field('text', 'name'), F::field('multicheckbox', 'topics')])],
        );
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabase();
    }

    /**
     * @param array<string, string|null> $text field name => value_text
     * @param array<string, string> $json field name => value_json
     */
    private function entry(string $submittedAt, array $text, array $json = [], string $status = 'complete'): int
    {
        $id = $this->seed->insert('form_entry', [
            'form_id'      => $this->form->id,
            'submitted_at' => $submittedAt,
            'status'       => $status,
        ]);
        foreach ($text as $name => $value) {
            $this->seed->insert('form_entry_value', [
                'form_entry_id' => $id,
                'field_name'    => $name,
                'value_text'    => $value,
                'value_json'    => $json[$name] ?? null,
            ]);
        }

        return $id;
    }

    /**
     * @param list<string>|null $excluded
     */
    private function export(EntryCsvWriter $writer, ?array $excluded = null): string
    {
        $stream = fopen('php://memory', mode: 'w+');
        static::assertIsResource($stream);
        null === $excluded ? $writer->write($stream, $this->form) : $writer->write($stream, $this->form, $excluded);
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    private function repository(): PhpDbEntryRepository
    {
        return new PhpDbEntryRepository($this->adapter);
    }
}
