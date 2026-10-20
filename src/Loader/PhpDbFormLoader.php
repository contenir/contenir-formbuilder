<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Loader;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Definition\GroupDefinition;
use Contenir\FormBuilder\Definition\RowDefinition;
use Contenir\FormBuilder\Definition\SectionDefinition;
use Contenir\FormBuilder\Persistence\Database;
use Contenir\FormBuilder\Persistence\PersistenceException;
use Contenir\FormBuilder\Persistence\Row;
use Override;
use PhpDb\Adapter\AdapterInterface;

use function array_map;

/**
 * Loads form definitions from the form_* tables through php-db, on MySQL or
 * SQLite.
 *
 * A form costs the same seven queries however big it is: the form, then its
 * sections, groups, rows and fields a level at a time, then its
 * notifications and webhooks.
 *
 * @api
 */
final readonly class PhpDbFormLoader implements FormLoaderInterface
{
    private Database $db;

    public function __construct(AdapterInterface $adapter)
    {
        $this->db = new Database($adapter);
    }

    /**
     * @param list<Row> $rows
     *
     * @return list<int>
     *
     * @throws PersistenceException
     */
    private static function ids(array $rows, string $column): array
    {
        return array_map(/** @throws PersistenceException */ static fn(Row $row): int => $row->int($column), $rows);
    }

    #[Override]
    public function listSummaries(): array
    {
        $select = $this->db
            ->sql
            ->select('form')
            ->columns(['form_id', 'slug', 'title', 'status'])
            ->order(['title ASC', 'form_id ASC']);

        $summaries = [];
        foreach ($this->db->rows($select) as $values) {
            $row         = new Row($values);
            $summaries[] = [
                'id'     => $row->int('form_id'),
                'slug'   => $row->string('slug'),
                'title'  => $row->string('title'),
                'status' => $row->string('status'),
            ];
        }

        return $summaries;
    }

    #[Override]
    public function loadAll(): array
    {
        $forms = [];
        foreach ($this->db->rows($this->db->sql->select('form')->order(['title ASC', 'form_id ASC'])) as $row) {
            $forms[] = $this->hydrate(new Row($row));
        }

        return $forms;
    }

    #[Override]
    public function loadById(int $formId): ?FormDefinition
    {
        $row = $this->db->row($this->db->sql->select('form')->where(['form_id' => $formId]));

        return null === $row ? null : $this->hydrate(new Row($row));
    }

    #[Override]
    public function loadBySlug(string $slug): ?FormDefinition
    {
        $row = $this->db->row($this->db->sql->select('form')->where(['slug' => $slug]));

        return null === $row ? null : $this->hydrate(new Row($row));
    }

    /**
     * The rows of a child table whose parent column holds $parentIds (or one
     * of them), in sort order; none when there are no parents.
     *
     * @param int|list<int> $parentIds
     *
     * @return list<Row>
     *
     * @throws PersistenceException
     */
    private function children(string $table, string $parentColumn, int|array $parentIds): array
    {
        if ([] === $parentIds) {
            return [];
        }
        $select = $this->db
            ->sql
            ->select($table)
            ->where([$parentColumn => $parentIds])
            ->order(['sort ASC', "{$table}_id ASC"]);

        $rows = [];
        foreach ($this->db->rows($select) as $row) {
            $rows[] = new Row($row);
        }

        return $rows;
    }

    /**
     * @throws PersistenceException
     */
    private function hydrate(Row $form): FormDefinition
    {
        $formId = $form->int('form_id');

        return new FormDefinition(
            id: $formId,
            slug: $form->string('slug'),
            title: $form->string('title'),
            description: $form->nullableString('description'),
            layoutMode: $form->string('layout_mode', default: FormDefinition::LAYOUT_SINGLE),
            submitLabel: $form->string('submit_label', default: 'Submit'),
            submitAlignment: $form->string('submit_alignment', default: 'left'),
            settings: $form->jsonMap('settings_json'),
            retentionDays: $form->nullableInt('retention_days'),
            status: $form->string('status', default: FormDefinition::STATUS_ACTIVE),
            sections: $this->sections($formId),
            notifications: array_map(
                DefinitionMapper::notification(...),
                $this->children('form_notification', 'form_id', $formId),
            ),
            webhooks: array_map(DefinitionMapper::webhook(...), $this->children('form_webhook', 'form_id', $formId)),
        );
    }

    /**
     * @return list<SectionDefinition>
     *
     * @throws PersistenceException
     */
    private function sections(int $formId): array
    {
        $sectionRows = $this->children('form_section', 'form_id', $formId);
        $groupRows   = $this->children('form_group', 'form_section_id', self::ids($sectionRows, 'form_section_id'));
        $rowRows     = $this->children('form_row', 'form_group_id', self::ids($groupRows, 'form_group_id'));

        $fieldsByRow = [];
        foreach ($this->children('form_field', 'form_row_id', self::ids($rowRows, 'form_row_id')) as $field) {
            $fieldsByRow[$field->int('form_row_id')][] = DefinitionMapper::field($field);
        }

        $rowsByGroup = [];
        foreach ($rowRows as $row) {
            $rowId                                     = $row->int('form_row_id');
            $rowsByGroup[$row->int('form_group_id')][] = new RowDefinition(
                id: $rowId,
                sort: $row->int('sort'),
                fields: $fieldsByRow[$rowId] ?? [],
            );
        }

        $groupsBySection = [];
        foreach ($groupRows as $group) {
            $groupId                                           = $group->int('form_group_id');
            $groupsBySection[$group->int('form_section_id')][] = new GroupDefinition(
                id: $groupId,
                legend: $group->nullableString('legend'),
                description: $group->nullableString('description'),
                sort: $group->int('sort'),
                rows: $rowsByGroup[$groupId] ?? [],
            );
        }

        return array_map(
            /** @throws PersistenceException */ static fn(Row $section): SectionDefinition => new SectionDefinition(
                id: $section->int('form_section_id'),
                key: $section->string('key'),
                legend: $section->nullableString('legend'),
                description: $section->nullableString('description'),
                sort: $section->int('sort'),
                groups: $groupsBySection[$section->int('form_section_id')] ?? [],
            ),
            $sectionRows,
        );
    }
}
