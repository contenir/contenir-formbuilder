<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Repository;

use Contenir\FormBuilder\Clock\SystemClock;
use Contenir\FormBuilder\Persistence\Database;
use Contenir\FormBuilder\Persistence\Json;
use Contenir\FormBuilder\Persistence\PersistenceException;
use Contenir\FormBuilder\Persistence\Row;
use DateInterval;
use Override;
use PhpDb\Adapter\AdapterInterface;
use PhpDb\Sql\Expression;
use PhpDb\Sql\Where;
use Psr\Clock\ClockInterface;

use function array_key_exists;
use function is_array;
use function is_scalar;
use function max;

/**
 * Entries in the form_entry and form_entry_value tables through php-db, on
 * MySQL or SQLite.
 *
 * Times are the clock's, to the second, in the clock's time zone, as the CMS
 * has always stored them: `submitted_at` when an entry is recorded, the
 * cutoff {@see findExpired()} compares it with, and the time
 * {@see markNotified()} notes.
 *
 * @api
 *
 * @mago-expect lint:too-many-methods One method per operation of EntryRepositoryInterface, plus the filters they share.
 */
final readonly class PhpDbEntryRepository implements EntryRepositoryInterface
{
    private const string DATETIME = 'Y-m-d H:i:s';

    /** The meta key {@see markNotified()} sets. */
    private const string META_NOTIFIED = 'notified_at';

    private Database $db;

    public function __construct(
        AdapterInterface $adapter,
        private ClockInterface $clock = new SystemClock(),
    ) {
        $this->db = new Database($adapter);
    }

    #[Override]
    public function countByStatus(int $formId, ?string $sinceDate = null, ?string $search = null): array
    {
        $select = $this->db
            ->sql
            ->select('form_entry')
            ->where((new EntryFilter($formId, null, $sinceDate, $search))->apply(...))
            ->columns(['status', 'total' => new Expression('COUNT(*)')])
            ->group('status');

        $counts = [];
        foreach ($this->db->rows($select) as $values) {
            $row                            = new Row($values);
            $counts[$row->string('status')] = $row->int('total');
        }

        return $counts;
    }

    #[Override]
    public function delete(int $entryId): void
    {
        $this->db->transaction(
            /** @throws PersistenceException */ function () use ($entryId): void {
                $this->deleteValues($entryId);
                $this->db->affectedRows($this->db->sql->delete('form_entry')->where(['form_entry_id' => $entryId]));
            },
        );
    }

    #[Override]
    public function fetchValues(int $entryId): array
    {
        return $this->db->rows(
            $this->db
                ->sql
                ->select('form_entry_value')
                ->where(['form_entry_id' => $entryId])
                ->order(['form_entry_value_id ASC']),
        );
    }

    #[Override]
    public function fetchValuesForEntries(array $entryIds): array
    {
        if ([] === $entryIds) {
            return [];
        }

        $select = $this->db
            ->sql
            ->select('form_entry_value')
            ->where(['form_entry_id' => $entryIds])
            ->order(['form_entry_value_id ASC']);

        $values = [];
        foreach ($this->db->rows($select) as $row) {
            $values[(new Row($row))->int('form_entry_id')][] = $row;
        }

        return $values;
    }

    #[Override]
    public function findEntry(int $entryId): ?array
    {
        return $this->db->row($this->db->sql->select('form_entry')->where(['form_entry_id' => $entryId]));
    }

    #[Override]
    public function findExpired(int $formId, int $retentionDays): array
    {
        $cutoff = $this->clock
            ->now()
            ->sub(new DateInterval('P' . max(1, $retentionDays) . 'D'))
            ->format(self::DATETIME);

        $select = $this->db
            ->sql
            ->select('form_entry')
            ->where(static function (Where $where) use ($formId, $cutoff): void {
                $where->equalTo('form_id', $formId);
                $where->notEqualTo('status', self::STATUS_REDACTED);
                $where->lessThan('submitted_at', $cutoff);
            })
            ->order(['form_entry_id ASC']);

        return $this->db->rows($select);
    }

    /**
     * @mago-expect lint:excessive-parameter-list The CMS's signature, kept so its callers move over unchanged.
     */
    #[Override]
    public function listEntries(
        int $formId,
        ?array $statuses = null,
        int $limit = 100,
        int $offset = 0,
        ?string $sinceDate = null,
        ?string $search = null,
    ): array {
        $select = $this->db
            ->sql
            ->select('form_entry')
            ->where((new EntryFilter($formId, $statuses, $sinceDate, $search))->apply(...))
            ->order(['submitted_at DESC', 'form_entry_id DESC'])
            ->limit($limit)
            ->offset($offset);

        return $this->db->rows($select);
    }

    #[Override]
    public function listEntriesBefore(int $formId, ?int $beforeId, int $limit, array $excludedStatuses = []): array
    {
        $select = $this->db
            ->sql
            ->select('form_entry')
            ->where(static function (Where $where) use ($formId, $beforeId, $excludedStatuses): void {
                $where->equalTo('form_id', $formId);
                if (null !== $beforeId) {
                    $where->lessThan('form_entry_id', $beforeId);
                }
                if ([] !== $excludedStatuses) {
                    $where->notIn('status', $excludedStatuses);
                }
            })
            ->order(['form_entry_id DESC'])
            ->limit($limit);

        return $this->db->rows($select);
    }

    #[Override]
    public function markNotified(int $entryId): void
    {
        $entry = $this->findEntry($entryId);
        if (null === $entry) {
            return;
        }

        $meta                      = (new Row($entry))->jsonMap('meta_json');
        $meta[self::META_NOTIFIED] = $this->now();
        $this->db->affectedRows(
            $this->db
                ->sql
                ->update('form_entry')
                ->set(['meta_json' => Json::encode($meta)])
                ->where(['form_entry_id' => $entryId]),
        );
    }

    /**
     * @mago-expect lint:excessive-parameter-list The CMS's signature, kept so its callers move over unchanged.
     * @mago-expect analysis:mixed-assignment Submitted values are untyped; each is stored as text or JSON by its type.
     */
    #[Override]
    public function record(int $formId, array $values, string $status, ?string $ip, ?int $userId, array $meta = []): int
    {
        return $this->db->transaction(
            /** @throws PersistenceException */ function () use ($formId, $values, $status, $ip, $userId, $meta): int {
                $entryId = $this->db->insert($this->db
                    ->sql
                    ->insert('form_entry')
                    ->values([
                        'form_id'      => $formId,
                        'submitted_at' => $this->now(),
                        'ip'           => $ip,
                        'user_id'      => $userId,
                        'status'       => $status,
                        'meta_json'    => Json::encode($meta),
                    ]));

                foreach ($values as $name => $value) {
                    $this->db->insert($this->db
                        ->sql
                        ->insert('form_entry_value')
                        ->values([
                            'form_entry_id' => $entryId,
                            'form_field_id' => null,
                            'field_name'    => $name,
                            'value_text'    => is_scalar($value) ? (string) $value : null,
                            'value_json'    => is_array($value) ? Json::encode($value) ?? '[]' : null,
                        ]));
                }

                return $entryId;
            },
        );
    }

    #[Override]
    public function redact(int $entryId): void
    {
        $this->db->transaction(
            /** @throws PersistenceException */ function () use ($entryId): void {
                $this->deleteValues($entryId);
                $this->db->affectedRows(
                    $this->db
                        ->sql
                        ->update('form_entry')
                        ->set(['status' => self::STATUS_REDACTED, 'ip' => null, 'meta_json' => null])
                        ->where(['form_entry_id' => $entryId]),
                );
            },
        );
    }

    #[Override]
    public function setStatus(int $entryId, string $status): void
    {
        $this->db->affectedRows(
            $this->db->sql->update('form_entry')->set(['status' => $status])->where(['form_entry_id' => $entryId]),
        );
    }

    #[Override]
    public function wasNotified(array $entry): bool
    {
        return array_key_exists(self::META_NOTIFIED, (new Row($entry))->jsonMap('meta_json'));
    }

    /**
     * @throws PersistenceException
     */
    private function deleteValues(int $entryId): void
    {
        $this->db->affectedRows($this->db->sql->delete('form_entry_value')->where(['form_entry_id' => $entryId]));
    }

    private function now(): string
    {
        return $this->clock->now()->format(self::DATETIME);
    }
}
