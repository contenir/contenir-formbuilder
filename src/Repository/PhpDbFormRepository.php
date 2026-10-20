<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Repository;

use Contenir\FormBuilder\Clock\SystemClock;
use Contenir\FormBuilder\Persistence\Database;
use Contenir\FormBuilder\Persistence\Json;
use Contenir\FormBuilder\Persistence\PersistenceException;
use Contenir\FormBuilder\Persistence\Row;
use Override;
use PhpDb\Adapter\AdapterInterface;
use PhpDb\Sql\Expression;
use PhpDb\Sql\Select;
use PhpDb\Sql\Where;
use Psr\Clock\ClockInterface;

use function array_key_exists;
use function array_splice;
use function array_values;
use function count;
use function ctype_digit;
use function is_array;
use function is_scalar;
use function is_string;
use function max;
use function min;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Writes form definitions to the form_* tables through php-db, on MySQL or
 * SQLite. A change that takes several statements (inserting a section with
 * its first group and row, say) is made in one transaction, or in the
 * caller's when one is open.
 *
 * A value given in `$data` that is neither a scalar nor null is stored as
 * NULL, except in the keys stored as JSON. `created` and `updated` are the
 * clock's time, to the second, in the clock's time zone, as the CMS has
 * always stored them.
 *
 * @api
 *
 * @mago-expect lint:too-many-methods One method per editor action, as FormRepositoryInterface lists them.
 * @mago-expect lint:kan-defect The same editor actions, counted as one class.
 * @mago-expect lint:cyclomatic-complexity The same editor actions, counted as one class.
 */
final readonly class PhpDbFormRepository implements FormRepositoryInterface
{
    private const string DATETIME = 'Y-m-d H:i:s';

    private const array SECTION_COLUMNS = ['key', 'legend', 'description', 'sort'];

    private const array GROUP_COLUMNS = ['legend', 'description', 'sort'];

    private const array FIELD_COLUMNS = ['label', 'description', 'placeholder', 'default_value'];

    /** Field data keys stored as JSON, and their columns. */
    private const array FIELD_JSON_COLUMNS = [
        'options'     => 'options_json',
        'validators'  => 'validators_json',
        'filters'     => 'filters_json',
        'conditional' => 'conditional_json',
    ];

    private const array NOTIFICATION_COLUMNS = [
        'name',
        'trigger',
        'to_address',
        'from_address',
        'reply_to',
        'subject',
        'body_template',
    ];

    private const array WEBHOOK_COLUMNS = ['name', 'url', 'method', 'secret'];

    private Database $db;

    public function __construct(
        AdapterInterface $adapter,
        private ClockInterface $clock = new SystemClock(),
    ) {
        $this->db = new Database($adapter);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function arrayOf(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * The field columns $data sets, other than its row, type and name.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, scalar|null>
     */
    private static function fieldValues(array $data): array
    {
        $values = [
            ...self::only($data, self::FIELD_COLUMNS),
            ...self::flags($data, ['show_label', 'required']),
            ...self::json($data, self::FIELD_JSON_COLUMNS),
        ];
        if (array_key_exists('col_span', $data)) {
            $values['col_span'] = max(1, min(4, self::intOf($data['col_span'])));
        }
        if (array_key_exists('sort', $data)) {
            $values['sort'] = self::intOf($data['sort']);
        }

        return $values;
    }

    /**
     * The keys of $data among $columns as 1 or 0, as PHP casts each to bool.
     *
     * @param array<string, mixed> $data
     * @param list<string> $columns
     *
     * @return array<string, int>
     */
    private static function flags(array $data, array $columns): array
    {
        $flags = [];
        foreach ($columns as $column) {
            if (! array_key_exists($column, $data)) {
                continue;
            }
            $flags[$column] = $data[$column] ? 1 : 0;
        }

        return $flags;
    }

    /**
     * The highest N among the values that are exactly `<prefix>N`, or 0 when
     * none is.
     *
     * @param list<mixed> $values
     *
     * @mago-expect analysis:mixed-assignment Column values are untyped; each is checked with is_string().
     */
    private static function highestSuffix(array $values, string $prefix): int
    {
        $highest = 0;
        foreach ($values as $value) {
            $suffix = is_string($value) && str_starts_with($value, $prefix) ? substr($value, strlen($prefix)) : '';
            if (! ctype_digit($suffix)) {
                continue;
            }
            $highest = max($highest, (int) $suffix);
        }

        return $highest;
    }

    private static function intOf(mixed $value): int
    {
        return is_scalar($value) ? (int) $value : 0;
    }

    /**
     * The keys of $data among $keys as JSON in their columns; null stays NULL.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $keys data key => column
     *
     * @return array<string, string|null>
     */
    private static function json(array $data, array $keys): array
    {
        $json = [];
        foreach ($keys as $key => $column) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $json[$column] = null === $data[$key] ? null : Json::encode(self::arrayOf($data[$key]));
        }

        return $json;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, scalar|null>
     */
    private static function notificationValues(array $data): array
    {
        return [
            ...self::only($data, self::NOTIFICATION_COLUMNS),
            ...self::json($data, ['conditions' => 'conditions_json']),
            ...self::flags($data, ['enabled']),
            ...self::sort($data),
        ];
    }

    /**
     * The keys of $data among $columns, each a scalar or null.
     *
     * @param array<string, mixed> $data
     * @param list<string> $columns
     *
     * @return array<string, scalar|null>
     */
    private static function only(array $data, array $columns): array
    {
        $values = [];
        foreach ($columns as $column) {
            if (! array_key_exists($column, $data)) {
                continue;
            }
            $values[$column] = self::scalar($data[$column]);
        }

        return $values;
    }

    /**
     * $ids with $id inserted at $position, clamped to the list.
     *
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private static function placed(array $ids, int $id, int $position): array
    {
        array_splice($ids, max(0, min($position, count($ids))), length: 0, replacement: [$id]);

        return $ids;
    }

    /**
     * @return scalar|null
     */
    private static function scalar(mixed $value): bool|float|int|string|null
    {
        return is_scalar($value) ? $value : null;
    }

    /**
     * `sort` from $data as a number, when it sets it.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, int>
     */
    private static function sort(array $data): array
    {
        return array_key_exists('sort', $data) ? ['sort' => self::intOf($data['sort'])] : [];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, scalar|null>
     */
    private static function webhookValues(array $data): array
    {
        $values = self::only($data, self::WEBHOOK_COLUMNS);
        if (array_key_exists('headers', $data)) {
            $values['headers_json'] = Json::encode(self::arrayOf($data['headers']));
        }

        return [...$values, ...self::flags($data, ['enabled']), ...self::sort($data)];
    }

    #[Override]
    public function createField(int $rowId, array $data): int
    {
        $values                = self::fieldValues($data);
        $values['form_row_id'] = $rowId;
        $values['type']        = self::scalar($data['type'] ?? null);
        $values['name']        = self::scalar($data['name'] ?? null);
        $values['sort']        ??= $this->nextSort('form_field', 'form_row_id', $rowId);

        return $this->db->insert($this->db->sql->insert('form_field')->values($values));
    }

    #[Override]
    public function createForm(array $data): int
    {
        $now = $this->now();

        return $this->db->insert($this->db
            ->sql
            ->insert('form')
            ->values([
                'slug'             => self::scalar($data['slug'] ?? null),
                'title'            => self::scalar($data['title'] ?? null),
                'description'      => self::scalar($data['description'] ?? null),
                'layout_mode'      => self::scalar($data['layout_mode'] ?? null) ?? 'single',
                'submit_label'     => self::scalar($data['submit_label'] ?? null) ?? 'Submit',
                'submit_alignment' => self::scalar($data['submit_alignment'] ?? null) ?? 'left',
                'settings_json'    => Json::encode(self::arrayOf($data['settings'] ?? null)),
                'retention_days'   => self::scalar($data['retention_days'] ?? null),
                'status'           => self::scalar($data['status'] ?? null) ?? 'active',
                'created'          => $now,
                'updated'          => $now,
            ]));
    }

    #[Override]
    public function createGroup(int $sectionId, array $data): int
    {
        return $this->db->insert($this->db
            ->sql
            ->insert('form_group')
            ->values([
                'form_section_id' => $sectionId,
                'legend'          => self::scalar($data['legend'] ?? null),
                'description'     => self::scalar($data['description'] ?? null),
                'sort'            => self::scalar($data['sort'] ?? null)
                ?? $this->nextSort('form_group', 'form_section_id', $sectionId),
            ]));
    }

    #[Override]
    public function createNotification(int $formId, array $data): int
    {
        return $this->db->insert($this->db
            ->sql
            ->insert('form_notification')
            ->values([
                ...self::notificationValues($data),
                'form_id' => $formId,
            ]));
    }

    #[Override]
    public function createRow(int $groupId, ?int $sort = null): int
    {
        return $this->db->insert($this->db
            ->sql
            ->insert('form_row')
            ->values([
                'form_group_id' => $groupId,
                'sort'          => $sort ?? $this->nextSort('form_row', 'form_group_id', $groupId),
            ]));
    }

    #[Override]
    public function createSection(int $formId, array $data): int
    {
        return $this->db->insert($this->db
            ->sql
            ->insert('form_section')
            ->values([
                'form_id'     => $formId,
                'key'         => self::scalar($data['key'] ?? null),
                'legend'      => self::scalar($data['legend'] ?? null),
                'description' => self::scalar($data['description'] ?? null),
                'sort'        => self::scalar($data['sort'] ?? null) ?? $this->nextSort(
                    'form_section',
                    'form_id',
                    $formId,
                ),
            ]));
    }

    #[Override]
    public function createWebhook(int $formId, array $data): int
    {
        return $this->db->insert($this->db
            ->sql
            ->insert('form_webhook')
            ->values([
                ...self::webhookValues($data),
                'form_id' => $formId,
            ]));
    }

    #[Override]
    public function deleteField(int $fieldId): void
    {
        $this->delete('form_field', $fieldId);
    }

    #[Override]
    public function deleteForm(int $formId): void
    {
        $this->delete('form', $formId);
    }

    #[Override]
    public function deleteGroup(int $groupId): void
    {
        $this->delete('form_group', $groupId);
    }

    #[Override]
    public function deleteNotification(int $notificationId): void
    {
        $this->delete('form_notification', $notificationId);
    }

    #[Override]
    public function deleteRow(int $rowId): void
    {
        $this->delete('form_row', $rowId);
    }

    #[Override]
    public function deleteSection(int $sectionId): void
    {
        $this->delete('form_section', $sectionId);
    }

    #[Override]
    public function deleteWebhook(int $webhookId): void
    {
        $this->delete('form_webhook', $webhookId);
    }

    #[Override]
    public function insertGroup(int $sectionId, int $position, ?string $legend = null): int
    {
        return $this->db->transaction(
            /** @throws PersistenceException */
            function () use ($sectionId, $position, $legend): int {
                $groupIds = $this->childIds('form_group', 'form_section_id', $sectionId);
                $groupId  = $this->createGroup($sectionId, ['legend' => '' === $legend ? null : $legend]);
                $this->renumber(
                    'form_group',
                    'form_section_id',
                    $sectionId,
                    self::placed($groupIds, $groupId, $position),
                );
                $this->createRow($groupId);

                return $groupId;
            },
        );
    }

    #[Override]
    public function insertRow(int $groupId, int $position): int
    {
        return $this->db->transaction(
            /** @throws PersistenceException */
            function () use ($groupId, $position): int {
                $rowIds = $this->childIds('form_row', 'form_group_id', $groupId);
                $rowId  = $this->createRow($groupId);
                $this->renumber('form_row', 'form_group_id', $groupId, self::placed($rowIds, $rowId, $position));

                return $rowId;
            },
        );
    }

    #[Override]
    public function insertSection(int $formId, int $position, ?string $legend = null, ?string $key = null): int
    {
        return $this->db->transaction(
            /** @throws PersistenceException */
            function () use ($formId, $position, $legend, $key): int {
                $sectionIds = $this->childIds('form_section', 'form_id', $formId);
                $sectionId  = $this->createSection($formId, [
                    'key'    => null === $key || '' === $key ? $this->nextSectionKey($formId) : $key,
                    'legend' => '' === $legend ? null : $legend,
                ]);
                $this->renumber('form_section', 'form_id', $formId, self::placed($sectionIds, $sectionId, $position));
                $this->createRow($this->createGroup($sectionId, []));

                return $sectionId;
            },
        );
    }

    #[Override]
    public function isSlugTaken(string $slug, ?int $excludeFormId = null): bool
    {
        $select = $this->db
            ->sql
            ->select('form')
            ->columns(['form_id'])
            ->where(static function (Where $where) use ($slug, $excludeFormId): void {
                $where->equalTo('slug', $slug);
                if (null !== $excludeFormId) {
                    $where->notEqualTo('form_id', $excludeFormId);
                }
            });

        return null !== $this->db->row($select);
    }

    #[Override]
    public function reorderFields(array $rowToFieldIds): void
    {
        $this->db->transaction(
            /** @throws PersistenceException */
            function () use ($rowToFieldIds): void {
                foreach ($rowToFieldIds as $rowId => $fieldIds) {
                    $this->renumber('form_field', 'form_row_id', $rowId, $fieldIds);
                }
            },
        );
    }

    #[Override]
    public function reorderRows(int $groupId, array $rowIds): void
    {
        $this->db->transaction(
            /** @throws PersistenceException */
            function () use ($groupId, $rowIds): void {
                $this->renumber('form_row', 'form_group_id', $groupId, $rowIds);
            },
        );
    }

    #[Override]
    public function spawnField(int $rowId, string $type, int $position): int
    {
        return $this->db->transaction(
            /** @throws PersistenceException */
            function () use ($rowId, $type, $position): int {
                $fieldIds = $this->childIds('form_field', 'form_row_id', $rowId);
                $fieldId  = $this->createField($rowId, [
                    'type'       => $type,
                    'name'       => $this->nextFieldName($rowId, $type),
                    'show_label' => true,
                ]);
                $this->renumber('form_field', 'form_row_id', $rowId, self::placed($fieldIds, $fieldId, $position));

                return $fieldId;
            },
        );
    }

    #[Override]
    public function spawnFieldRow(int $groupId, int $fieldId, int $position): int
    {
        return $this->db->transaction(
            /** @throws PersistenceException */
            function () use ($groupId, $fieldId, $position): int {
                $rowId = $this->insertRow($groupId, $position);
                $this->renumber('form_field', 'form_row_id', $rowId, [$fieldId]);

                return $rowId;
            },
        );
    }

    #[Override]
    public function updateField(int $fieldId, array $data): void
    {
        $this->update('form_field', $fieldId, [...self::only($data, ['type', 'name']), ...self::fieldValues($data)]);
    }

    /**
     * A key given as null is left alone, except `description` and
     * `retention_days`, which a null clears. Empty settings are left alone.
     */
    #[Override]
    public function updateForm(int $formId, array $data): void
    {
        $update = [];
        foreach (['slug', 'title', 'layout_mode', 'submit_label', 'submit_alignment', 'status'] as $column) {
            $value = self::scalar($data[$column] ?? null);
            if (null === $value) {
                continue;
            }
            $update[$column] = $value;
        }
        $settings = Json::encode(self::arrayOf($data['settings'] ?? null));
        if (null !== $settings) {
            $update['settings_json'] = $settings;
        }

        $this->update('form', $formId, [
            ...$update,
            ...self::only($data, ['description', 'retention_days']),
            'updated' => $this->now(),
        ]);
    }

    #[Override]
    public function updateGroup(int $groupId, array $data): void
    {
        $this->update('form_group', $groupId, self::only($data, self::GROUP_COLUMNS));
    }

    #[Override]
    public function updateNotification(int $notificationId, array $data): void
    {
        $this->update('form_notification', $notificationId, self::notificationValues($data));
    }

    #[Override]
    public function updateSection(int $sectionId, array $data): void
    {
        $this->update('form_section', $sectionId, self::only($data, self::SECTION_COLUMNS));
    }

    #[Override]
    public function updateWebhook(int $webhookId, array $data): void
    {
        $this->update('form_webhook', $webhookId, self::webhookValues($data));
    }

    /**
     * The ids of a parent's children, in their current order.
     *
     * @return list<int>
     *
     * @throws PersistenceException
     */
    private function childIds(string $table, string $parentColumn, int $parentId): array
    {
        $select = $this->db
            ->sql
            ->select($table)
            ->columns(["{$table}_id"])
            ->where([$parentColumn => $parentId])
            ->order(['sort ASC', "{$table}_id ASC"]);

        $ids = [];
        foreach ($this->db->rows($select) as $row) {
            $ids[] = (new Row($row))->int("{$table}_id");
        }

        return $ids;
    }

    /**
     * @throws PersistenceException
     */
    private function delete(string $table, int $id): void
    {
        $this->db->affectedRows($this->db->sql->delete($table)->where(["{$table}_id" => $id]));
    }

    /**
     * `<type>_N`, one past the highest N of that type in the form the row is
     * in: the form's sections, groups, rows and fields, reached from the row.
     *
     * @throws PersistenceException
     */
    private function nextFieldName(int $rowId, string $type): string
    {
        $select = (new Select(['target' => 'form_row']))->columns([])
            ->join(['tg' => 'form_group'], 'target.form_group_id = tg.form_group_id', [])
            ->join(['ts' => 'form_section'], 'tg.form_section_id = ts.form_section_id', [])
            ->join(['s' => 'form_section'], 's.form_id = ts.form_id', [])
            ->join(['g' => 'form_group'], 'g.form_section_id = s.form_section_id', [])
            ->join(['r' => 'form_row'], 'r.form_group_id = g.form_group_id', [])
            ->join(['f' => 'form_field'], 'f.form_row_id = r.form_row_id', ['name'])
            ->where(['target.form_row_id' => $rowId]);

        return "{$type}_" . (self::highestSuffix($this->db->column($select), "{$type}_") + 1);
    }

    /**
     * `section-N`, one past the highest N the form's section keys use.
     *
     * @throws PersistenceException
     */
    private function nextSectionKey(int $formId): string
    {
        $select = $this->db->sql->select('form_section')->columns(['key'])->where(['form_id' => $formId]);

        return 'section-' . (self::highestSuffix($this->db->column($select), 'section-') + 1);
    }

    /**
     * One past the highest sort among a parent's children, or 1 for the first.
     *
     * @throws PersistenceException
     */
    private function nextSort(string $table, string $parentColumn, int $parentId): int
    {
        $select = $this->db
            ->sql
            ->select($table)
            ->columns(['highest' => new Expression('MAX(sort)')])
            ->where([$parentColumn => $parentId]);

        return (new Row($this->db->row($select) ?? []))->int('highest') + 1;
    }

    private function now(): string
    {
        return $this->clock->now()->format(self::DATETIME);
    }

    /**
     * Gives each child its position in $ids (from 1) as its sort, under
     * $parentId, moving any that had another parent.
     *
     * @param list<int> $ids
     *
     * @throws PersistenceException
     */
    private function renumber(string $table, string $parentColumn, int $parentId, array $ids): void
    {
        foreach (array_values($ids) as $index => $id) {
            $this->db->affectedRows(
                $this->db
                    ->sql
                    ->update($table)
                    ->set(['sort' => $index + 1, $parentColumn => $parentId])
                    ->where(["{$table}_id" => $id]),
            );
        }
    }

    /**
     * Sets the columns of one row; does nothing when there are none to set.
     *
     * @param array<string, scalar|null> $values
     *
     * @throws PersistenceException
     */
    private function update(string $table, int $id, array $values): void
    {
        if ([] === $values) {
            return;
        }
        $this->db->affectedRows($this->db->sql->update($table)->set($values)->where(["{$table}_id" => $id]));
    }
}
