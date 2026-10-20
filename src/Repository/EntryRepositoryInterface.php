<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Repository;

use Contenir\FormBuilder\Persistence\PersistenceException;

/**
 * Form submissions (entries) and the values submitted with them.
 *
 * Entries are rows of `form_entry` as arrays keyed by column; values are rows
 * of `form_entry_value`, one per field, holding a scalar in `value_text` or
 * an array as JSON in `value_json`. Numbers may come back as strings,
 * depending on the database.
 *
 * An entry is never rewritten, only moved between statuses, and redacted
 * once its form's retention period has passed: its values, address and meta
 * are deleted and the row is kept, so counts still add up.
 *
 * @api
 *
 * @mago-expect lint:too-many-methods The CMS's entry repository, one method per operation, kept so its callers move over unchanged.
 */
interface EntryRepositoryInterface
{
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_COMPLETE = 'complete';

    public const string STATUS_SPAM = 'spam';

    public const string STATUS_ARCHIVE = 'archive';

    public const string STATUS_REDACTED = 'redacted';

    /**
     * How many of the form's entries have each status, filtered as
     * {@see listEntries()} filters them but by every status, so a status with
     * none has no key.
     *
     * @return array<string, int>
     *
     * @throws PersistenceException
     */
    public function countByStatus(int $formId, ?string $sinceDate = null, ?string $search = null): array;

    /**
     * Deletes the entry and its values.
     *
     * @throws PersistenceException
     */
    public function delete(int $entryId): void;

    /**
     * The entry's values, in the order they were stored.
     *
     * @return list<array<string, mixed>>
     *
     * @throws PersistenceException
     */
    public function fetchValues(int $entryId): array;

    /**
     * The values of each of the entries, in one query, keyed by entry id. An
     * entry with no values has no key.
     *
     * @param list<int> $entryIds
     *
     * @return array<int, list<array<string, mixed>>>
     *
     * @throws PersistenceException
     */
    public function fetchValuesForEntries(array $entryIds): array;

    /**
     * @return array<string, mixed>|null
     *
     * @throws PersistenceException
     */
    public function findEntry(int $entryId): ?array;

    /**
     * The form's entries, other than redacted ones, submitted more than
     * $retentionDays days ago (at least one) by the clock.
     *
     * @return list<array<string, mixed>>
     *
     * @throws PersistenceException
     */
    public function findExpired(int $formId, int $retentionDays): array;

    /**
     * A page of the form's entries, newest first, in any of $statuses (all
     * when null or empty), submitted on or after $sinceDate, and matching
     * $search: a number matches the entry id or part of the address, other
     * text part of the address.
     *
     * @param list<string>|null $statuses
     *
     * @return list<array<string, mixed>>
     *
     * @throws PersistenceException
     *
     * @mago-expect lint:excessive-parameter-list The CMS's signature, kept so its callers move over unchanged.
     */
    public function listEntries(
        int $formId,
        ?array $statuses = null,
        int $limit = 100,
        int $offset = 0,
        ?string $sinceDate = null,
        ?string $search = null,
    ): array;

    /**
     * Up to $limit of the form's entries, newest first, before $beforeId (or
     * from the newest), leaving out the statuses given. Reading every entry
     * a batch at a time this way never makes the database skip rows it has
     * already returned.
     *
     * @param list<string> $excludedStatuses
     *
     * @return list<array<string, mixed>>
     *
     * @throws PersistenceException
     */
    public function listEntriesBefore(int $formId, ?int $beforeId, int $limit, array $excludedStatuses = []): array;

    /**
     * Notes in the entry's meta that its notifications and webhooks have gone
     * out, so they are never sent for it again.
     *
     * @throws PersistenceException
     */
    public function markNotified(int $entryId): void;

    /**
     * Stores an entry and its values together, submitted now.
     *
     * @param array<string, mixed> $values field name => value
     * @param array<string, mixed> $meta
     *
     * @return int The new entry's id
     *
     * @throws PersistenceException
     *
     * @mago-expect lint:excessive-parameter-list The CMS's signature, kept so its callers move over unchanged.
     */
    public function record(
        int $formId,
        array $values,
        string $status,
        ?string $ip,
        ?int $userId,
        array $meta = [],
    ): int;

    /**
     * Deletes the entry's values and clears its address and meta, and marks
     * it redacted; the row stays, so counts still add up. Files its values
     * point to are the caller's to delete first.
     *
     * @throws PersistenceException
     */
    public function redact(int $entryId): void;

    /**
     * @throws PersistenceException
     */
    public function setStatus(int $entryId, string $status): void;

    /**
     * Whether an entry's meta says its notifications and webhooks have gone
     * out, from {@see markNotified()}.
     *
     * @param array<string, mixed> $entry
     */
    public function wasNotified(array $entry): bool;
}
