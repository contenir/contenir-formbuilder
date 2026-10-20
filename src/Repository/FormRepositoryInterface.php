<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Repository;

use Contenir\FormBuilder\Persistence\PersistenceException;

/**
 * Writes form definitions: the form, its layout (sections, groups, rows and
 * fields), its notifications and its webhooks. Each method is one change an
 * editor makes; reading a whole form is {@see \Contenir\FormBuilder\Loader\FormLoaderInterface}'s job.
 *
 * The `$data` arrays use the column names (`layout_mode`, `show_label`,
 * `to_address` …), with `settings`, `options`, `validators`, `filters`,
 * `conditional`, `conditions` and `headers` given as arrays and stored as
 * JSON. An update changes only the keys it is given.
 *
 * Every method throws {@see PersistenceException} when the database fails.
 *
 * @api
 *
 * @mago-expect lint:too-many-methods The CMS's form repository, one method per editor action, kept so its callers move over unchanged.
 */
interface FormRepositoryInterface
{
    /**
     * @param array<string, mixed> $data `type` and `name` are required; `sort` defaults to after the last field
     *
     * @throws PersistenceException
     */
    public function createField(int $rowId, array $data): int;

    /**
     * @param array<string, mixed> $data `slug` and `title` are required
     *
     * @throws PersistenceException
     */
    public function createForm(array $data): int;

    /**
     * @param array<string, mixed> $data `sort` defaults to after the last group
     *
     * @throws PersistenceException
     */
    public function createGroup(int $sectionId, array $data): int;

    /**
     * @param array<string, mixed> $data
     *
     * @throws PersistenceException
     */
    public function createNotification(int $formId, array $data): int;

    /**
     * @param int|null $sort defaults to after the last row
     *
     * @throws PersistenceException
     */
    public function createRow(int $groupId, ?int $sort = null): int;

    /**
     * @param array<string, mixed> $data `key` is required; `sort` defaults to after the last section
     *
     * @throws PersistenceException
     */
    public function createSection(int $formId, array $data): int;

    /**
     * @param array<string, mixed> $data
     *
     * @throws PersistenceException
     */
    public function createWebhook(int $formId, array $data): int;

    /**
     * @throws PersistenceException
     */
    public function deleteField(int $fieldId): void;

    /**
     * Deletes the form, and with it (through the foreign keys) its layout,
     * notifications, webhooks and entries.
     *
     * @throws PersistenceException
     */
    public function deleteForm(int $formId): void;

    /**
     * @throws PersistenceException
     */
    public function deleteGroup(int $groupId): void;

    /**
     * @throws PersistenceException
     */
    public function deleteNotification(int $notificationId): void;

    /**
     * @throws PersistenceException
     */
    public function deleteRow(int $rowId): void;

    /**
     * @throws PersistenceException
     */
    public function deleteSection(int $sectionId): void;

    /**
     * @throws PersistenceException
     */
    public function deleteWebhook(int $webhookId): void;

    /**
     * Inserts a group at $position (0 is first) holding one empty row, and
     * renumbers the section's groups.
     *
     * @throws PersistenceException
     */
    public function insertGroup(int $sectionId, int $position, ?string $legend = null): int;

    /**
     * Inserts an empty row at $position (0 is first) and renumbers the
     * group's rows.
     *
     * @throws PersistenceException
     */
    public function insertRow(int $groupId, int $position): int;

    /**
     * Inserts a section at $position (0 is first) holding one group with one
     * empty row, and renumbers the form's sections. Without a key, the
     * section is keyed `section-N`, one past the highest N the form uses.
     *
     * @throws PersistenceException
     */
    public function insertSection(int $formId, int $position, ?string $legend = null, ?string $key = null): int;

    /**
     * @throws PersistenceException
     */
    public function isSlugTaken(string $slug, ?int $excludeFormId = null): bool;

    /**
     * Puts the fields of each row in the order given, moving any that were
     * in another row. A field's width is left alone.
     *
     * @param array<int, list<int>> $rowToFieldIds row id => field ids
     *
     * @throws PersistenceException
     */
    public function reorderFields(array $rowToFieldIds): void;

    /**
     * Puts the rows in $groupId in the order given, moving any that were in
     * another group.
     *
     * @param list<int> $rowIds
     *
     * @throws PersistenceException
     */
    public function reorderRows(int $groupId, array $rowIds): void;

    /**
     * Creates a field of $type at $position (0 is first) in the row, named
     * `<type>_N`, one past the highest N of that type in the form.
     *
     * @throws PersistenceException
     */
    public function spawnField(int $rowId, string $type, int $position): int;

    /**
     * Inserts a row at $position (0 is first) in the group and moves the
     * field into it, alone. Returns the new row's id.
     *
     * @throws PersistenceException
     */
    public function spawnFieldRow(int $groupId, int $fieldId, int $position): int;

    /**
     * Does nothing when the field does not exist.
     *
     * @param array<string, mixed> $data
     *
     * @throws PersistenceException
     */
    public function updateField(int $fieldId, array $data): void;

    /**
     * @param array<string, mixed> $data
     *
     * @throws PersistenceException
     */
    public function updateForm(int $formId, array $data): void;

    /**
     * @param array<string, mixed> $data
     *
     * @throws PersistenceException
     */
    public function updateGroup(int $groupId, array $data): void;

    /**
     * Does nothing when the notification does not exist.
     *
     * @param array<string, mixed> $data
     *
     * @throws PersistenceException
     */
    public function updateNotification(int $notificationId, array $data): void;

    /**
     * @param array<string, mixed> $data
     *
     * @throws PersistenceException
     */
    public function updateSection(int $sectionId, array $data): void;

    /**
     * Does nothing when the webhook does not exist.
     *
     * @param array<string, mixed> $data
     *
     * @throws PersistenceException
     */
    public function updateWebhook(int $webhookId, array $data): void;
}
