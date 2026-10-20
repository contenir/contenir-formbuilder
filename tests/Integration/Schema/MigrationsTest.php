<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Integration\Schema;

use Contenir\FormBuilder\Schema\Version20261020120000CreateFormTables;
use Contenir\FormBuilder\Tests\TestAsset\Database\TestDatabase;
use Contenir\FormBuilder\Tests\Trait\TestDatabaseTrait;
use PhpDb\Adapter\Adapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_column;
use function count;
use function sort;

#[Group('integration')]
final class MigrationsTest extends TestCase
{
    use TestDatabaseTrait;

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function tableProvider(): array
    {
        return [
            'form'              => [
                'form',
                [
                    'created',
                    'description',
                    'form_id',
                    'layout_mode',
                    'retention_days',
                    'settings_json',
                    'slug',
                    'status',
                    'submit_alignment',
                    'submit_label',
                    'title',
                    'updated',
                ],
            ],
            'form_section'      => [
                'form_section',
                ['description', 'form_id', 'form_section_id', 'key', 'legend', 'sort'],
            ],
            'form_group'        => [
                'form_group',
                ['description', 'form_group_id', 'form_section_id', 'legend', 'sort'],
            ],
            'form_row'          => ['form_row', ['form_group_id', 'form_row_id', 'sort']],
            'form_field'        => [
                'form_field',
                [
                    'col_span',
                    'conditional_json',
                    'default_value',
                    'description',
                    'filters_json',
                    'form_field_id',
                    'form_row_id',
                    'label',
                    'name',
                    'options_json',
                    'placeholder',
                    'required',
                    'show_label',
                    'sort',
                    'type',
                    'validators_json',
                ],
            ],
            'form_notification' => [
                'form_notification',
                [
                    'body_template',
                    'conditions_json',
                    'enabled',
                    'form_id',
                    'form_notification_id',
                    'from_address',
                    'name',
                    'reply_to',
                    'sort',
                    'subject',
                    'to_address',
                    'trigger',
                ],
            ],
            'form_webhook'      => [
                'form_webhook',
                [
                    'enabled',
                    'form_id',
                    'form_webhook_id',
                    'headers_json',
                    'method',
                    'name',
                    'secret',
                    'sort',
                    'url',
                ],
            ],
            'form_entry'        => [
                'form_entry',
                [
                    'form_entry_id',
                    'form_id',
                    'ip',
                    'meta_json',
                    'status',
                    'submitted_at',
                    'user_id',
                ],
            ],
            'form_entry_value'  => [
                'form_entry_value',
                [
                    'field_name',
                    'form_entry_id',
                    'form_entry_value_id',
                    'form_field_id',
                    'value_json',
                    'value_text',
                ],
            ],
        ];
    }

    #[Test]
    public function aFreshDatabaseIsCreatedTableByTable(): void
    {
        TestDatabase::drop($this->adapter);

        $result = (new Version20261020120000CreateFormTables())->up(
            $this->adapter,
            TestDatabase::inspector($this->adapter),
        );

        static::assertSame(
            [true, TestDatabase::isMysql() ? 9 : 22],
            [$result->isSuccess(), count($result->executedSql)],
        );
    }

    #[Test]
    public function aTableAlreadyThereIsSkippedAndTheRestAreCreated(): void
    {
        foreach (['form_entry_value', 'form_entry'] as $table) {
            $this->adapter->query("DROP TABLE {$table}", Adapter::QUERY_MODE_EXECUTE);
        }

        $result = (new Version20261020120000CreateFormTables())->up(
            $this->adapter,
            TestDatabase::inspector($this->adapter),
        );

        static::assertSame(
            [TestDatabase::isMysql() ? 2 : 7, 7],
            [count($result->executedSql), count($result->skippedOperations)],
        );
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('tableProvider')]
    public function createsEachTableWithTheInstallersColumns(string $table, array $expected): void
    {
        $columns = array_column(TestDatabase::inspector($this->adapter)->getColumns($table), 'name');
        sort($columns);

        static::assertSame($expected, $columns);
    }

    #[Test]
    public function createsTheSameIndexesAsTheInstaller(): void
    {
        $migrated = $this->indexes();
        TestDatabase::drop($this->adapter);
        TestDatabase::installAsTheCmsDid($this->adapter);

        static::assertSame($this->indexes(), $migrated);
    }

    #[Test]
    public function describesItself(): void
    {
        $migration = new Version20261020120000CreateFormTables();

        static::assertSame(
            [
                '20261020120000',
                'Create the form builder tables (form, form_section, form_group, form_row, form_field, '
                    . 'form_notification, form_webhook, form_entry, form_entry_value)',
            ],
            [$migration->getVersion(), $migration->getDescription()],
        );
    }

    #[Test]
    public function nothingIsPendingAfterMigrating(): void
    {
        static::assertSame([], TestDatabase::migrations($this->adapter)->getPendingMigrations());
    }

    #[Test]
    public function onASiteTheCmsInstalledTheBaselineOnlyRecordsItself(): void
    {
        TestDatabase::drop($this->adapter);
        TestDatabase::installAsTheCmsDid($this->adapter);
        $this->adapter->query(
            "INSERT INTO form (slug, title) VALUES ('contact', 'Contact')",
            Adapter::QUERY_MODE_EXECUTE,
        );
        $runner = TestDatabase::migrations($this->adapter);
        $runner->ensureMigrationsTable();

        $results = $runner->runPending();

        static::assertSame(
            [['skipped', 1, ['20261020120000']]],
            [[
                $results[0]['result']->isSkipped() ? 'skipped' : 'ran',
                $this->formCount(),
                $runner->getAppliedVersions(),
            ]],
        );
    }

    #[Test]
    public function previewListsTheStatementsWithoutRunningThem(): void
    {
        TestDatabase::drop($this->adapter);

        $statements = (new Version20261020120000CreateFormTables())->preview(
            $this->adapter,
            TestDatabase::inspector($this->adapter),
        );

        static::assertSame(
            [TestDatabase::isMysql() ? 9 : 22, false],
            [count($statements), TestDatabase::inspector($this->adapter)->tableExists('form')],
        );
    }

    #[Test]
    public function runningAgainOverItsOwnTablesChangesNothing(): void
    {
        $result = (new Version20261020120000CreateFormTables())->up(
            $this->adapter,
            TestDatabase::inspector($this->adapter),
        );

        static::assertTrue($result->isSkipped());
    }

    protected function setUp(): void
    {
        $this->setUpDatabase();
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabase();
    }

    private function formCount(): int
    {
        $row = $this->adapter->query('SELECT COUNT(*) AS total FROM form', Adapter::QUERY_MODE_EXECUTE)->current();

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Each table's index names, sorted.
     *
     * @return array<string, list<string>>
     */
    private function indexes(): array
    {
        $metadata = TestDatabase::metadata($this->adapter);
        $indexes  = [];
        foreach (TestDatabase::TABLES as $table) {
            if ('migrations' === $table) {
                continue;
            }
            $names = [];
            foreach ($metadata->getConstraints($table) as $constraint) {
                $names[] = "{$constraint->getType()} {$constraint->getName()}";
            }
            sort($names);
            $indexes[$table] = $names;
        }

        return $indexes;
    }
}
