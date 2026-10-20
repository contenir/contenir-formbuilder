<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Persistence;

use Closure;
use PDO;
use PDOException;
use PhpDb\Adapter\AdapterInterface;
use PhpDb\Adapter\Driver\ResultInterface;
use PhpDb\Exception\ExceptionInterface;
use PhpDb\Sql\Insert;
use PhpDb\Sql\PreparableSqlInterface;
use PhpDb\Sql\Select;
use PhpDb\Sql\Sql;

use function array_values;
use function is_array;
use function is_string;

/**
 * Runs php-db statements for the loader and repositories, so that every
 * database failure reaches them as a {@see PersistenceException}.
 *
 * @internal
 */
final readonly class Database
{
    public Sql $sql;

    public function __construct(
        private AdapterInterface $adapter,
    ) {
        $this->sql = new Sql($adapter);
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<string, mixed>
     *
     * @mago-expect analysis:mixed-assignment Column values are untyped; callers read them through Row or cast them.
     */
    private static function columns(array $values): array
    {
        $columns = [];
        foreach ($values as $name => $value) {
            if (! is_string($name)) {
                continue;
            }
            $columns[$name] = $value;
        }

        return $columns;
    }

    /**
     * @throws PersistenceException
     */
    public function affectedRows(PreparableSqlInterface $statement): int
    {
        return $this->execute($statement)->getAffectedRows();
    }

    /**
     * The first column of every row a select returns.
     *
     * @return list<mixed>
     *
     * @throws PersistenceException
     */
    public function column(Select $select): array
    {
        $values = [];
        foreach ($this->rows($select) as $row) {
            $values[] = array_values($row)[0] ?? null;
        }

        return $values;
    }

    /**
     * Run an insert and return the id the database generated.
     *
     * @throws PersistenceException
     */
    public function insert(Insert $insert): int
    {
        return (int) $this->execute($insert)->getGeneratedValue();
    }

    /**
     * The first row a select returns, if any.
     *
     * @return array<string, mixed>|null
     *
     * @throws PersistenceException
     */
    public function row(Select $select): ?array
    {
        return $this->rows($select)[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws PersistenceException
     *
     * @mago-expect analysis:mixed-assignment Result rows are untyped; each is checked with is_array().
     */
    public function rows(Select $select): array
    {
        $rows = [];
        foreach ($this->execute($select) as $values) {
            $rows[] = self::columns(is_array($values) ? $values : []);
        }

        return $rows;
    }

    /**
     * Run $work in a transaction, committed when it returns and rolled back
     * when it throws. Inside a transaction someone else began, on this
     * connection or straight on its PDO, $work joins that one instead.
     *
     * @template T
     *
     * @param Closure(): T $work
     *
     * @return T
     *
     * @throws PersistenceException
     */
    public function transaction(Closure $work): mixed
    {
        $connection = $this->adapter->getDriver()->getConnection();
        $resource   = $connection->getResource();
        if ($connection->inTransaction() || $resource instanceof PDO && $resource->inTransaction()) {
            return $work();
        }

        $connection->beginTransaction();
        $committed = false;
        try {
            $result = $work();
            $connection->commit();
            $committed = true;
        } finally {
            if (! $committed) {
                $connection->rollback();
            }
        }

        return $result;
    }

    /**
     * @throws PersistenceException
     */
    private function execute(PreparableSqlInterface $statement): ResultInterface
    {
        try {
            $result = $this->sql->prepareStatementForSqlObject($statement)->execute();
        } catch (ExceptionInterface|PDOException $e) {
            throw PersistenceException::failed($e);
        }

        return $result ?? throw PersistenceException::noResult();
    }
}
