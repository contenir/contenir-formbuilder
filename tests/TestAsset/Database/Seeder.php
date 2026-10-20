<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\TestAsset\Database;

use PhpDb\Adapter\AdapterInterface;
use PhpDb\Sql\Sql;

use function is_array;
use function is_scalar;

/**
 * Writes rows straight into the form builder's tables, so a test can set up
 * what it reads without going through the code under test.
 */
final readonly class Seeder
{
    private Sql $sql;

    public function __construct(AdapterInterface $adapter)
    {
        $this->sql = new Sql($adapter);
    }

    /**
     * A form with one section, group and row, returning the ids of each.
     *
     * @param array<string, scalar|null> $form
     *
     * @return array{form: int, section: int, group: int, row: int}
     */
    public function form(array $form = []): array
    {
        $formId    = $this->insert('form', [...['slug' => 'contact', 'title' => 'Contact'], ...$form]);
        $sectionId = $this->insert('form_section', ['form_id' => $formId, 'key' => 'main', 'sort' => 1]);
        $groupId   = $this->insert('form_group', ['form_section_id' => $sectionId, 'sort' => 1]);
        $rowId     = $this->insert('form_row', ['form_group_id' => $groupId, 'sort' => 1]);

        return ['form' => $formId, 'section' => $sectionId, 'group' => $groupId, 'row' => $rowId];
    }

    /**
     * @param array<string, scalar|null> $values
     *
     * @return int The new row's id
     */
    public function insert(string $table, array $values): int
    {
        $result = $this->sql->prepareStatementForSqlObject($this->sql->insert($table)->values($values))->execute();

        return (int) $result?->getGeneratedValue();
    }

    /**
     * The columns of a table's rows, by id, each value as a string (or null),
     * so MySQL and SQLite read the same.
     *
     * @param list<string> $columns
     * @param array<string, scalar|null> $where
     *
     * @return list<array<string, string|null>>
     */
    public function rows(string $table, array $columns, array $where = []): array
    {
        $select = $this->sql->select($table)->columns($columns)->order("{$table}_id ASC");
        if ([] !== $where) {
            $select->where($where);
        }

        $rows = [];
        foreach ($this->sql->prepareStatementForSqlObject($select)->execute() ?? [] as $values) {
            $row = [];
            foreach (is_array($values) ? $values : [] as $column => $value) {
                $row[(string) $column] = is_scalar($value) ? (string) $value : null;
            }
            $rows[] = $row;
        }

        return $rows;
    }
}
