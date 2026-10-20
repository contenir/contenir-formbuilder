<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Integration\Persistence;

use Contenir\FormBuilder\Persistence\Database;
use Contenir\FormBuilder\Persistence\PersistenceException;
use Contenir\FormBuilder\Tests\TestAsset\Attempt;
use Contenir\FormBuilder\Tests\TestAsset\Database\NullResultStatement;
use Contenir\FormBuilder\Tests\TestAsset\Database\ObjectRowResult;
use PDO;
use PDOException;
use PhpDb\Adapter\Adapter;
use PhpDb\Adapter\Driver\Pdo\Result;
use PhpDb\Adapter\Driver\Pdo\Statement;
use PhpDb\Sqlite\AdapterPlatform;
use PhpDb\Sqlite\Pdo\Connection;
use PhpDb\Sqlite\Pdo\Driver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function str_starts_with;

/**
 * How statements, results, failures and transactions reach the loader and
 * repositories. A plain in-memory SQLite database with one table: these cases
 * do not depend on the platform.
 */
#[Group('integration')]
final class DatabaseTest extends TestCase
{
    private PDO $pdo;

    #[Test]
    public function aFailingStatementIsAPersistenceFailure(): void
    {
        $db = $this->database();

        try {
            $db->rows($db->sql->select('missing_table'));
            static::fail('The statement did not fail');
        } catch (PersistenceException $e) {
            static::assertSame(
                [PDOException::class, true, 0],
                [
                    $e->getPrevious()::class,
                    str_starts_with($e->getMessage(), "The form builder's database failed: "),
                    $e->getCode(),
                ],
            );
        }
    }

    #[Test]
    public function affectedRowsCountsTheChange(): void
    {
        $db = $this->database();

        static::assertSame(2, $db->affectedRows($db->sql->update('t')->set(['name' => 'z'])));
    }

    #[Test]
    public function aStatementWithNoResultIsAPersistenceFailure(): void
    {
        $db = $this->database(new NullResultStatement());

        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage("The form builder's database returned no result");

        $db->rows($db->sql->select('t'));
    }

    #[Test]
    public function aTransactionIsCommittedWhenItsWorkReturns(): void
    {
        $db = $this->database();

        $id = $db->transaction(static fn(): int => $db->insert($db->sql->insert('t')->values(['name' => 'c'])));

        static::assertSame([3, false, 3], [$id, $this->pdo->inTransaction(), $this->rowCount()]);
    }

    #[Test]
    public function aTransactionIsRolledBackWhenItsWorkThrows(): void
    {
        $db = $this->database();

        $failure = Attempt::of(static fn(): mixed => $db->transaction(static function () use ($db): void {
            $db->insert($db->sql->insert('t')->values(['name' => 'c']));

            throw new RuntimeException('stop');
        }));

        static::assertSame(
            [RuntimeException::class, false, 2],
            [$failure, $this->pdo->inTransaction(), $this->rowCount()],
        );
    }

    #[Test]
    public function aTransactionJoinsOneBegunOnTheConnection(): void
    {
        $adapter = $this->adapter();
        $db      = new Database($adapter);
        $adapter->getDriver()->getConnection()->beginTransaction();

        $db->transaction(static fn(): int => $db->insert($db->sql->insert('t')->values(['name' => 'c'])));
        $stillOpen = $this->pdo->inTransaction();
        $adapter->getDriver()->getConnection()->rollback();

        static::assertSame([true, 2], [$stillOpen, $this->rowCount()]);
    }

    #[Test]
    public function aTransactionJoinsOneBegunOnThePdo(): void
    {
        $db = $this->database();
        $this->pdo->beginTransaction();

        $db->transaction(static fn(): int => $db->insert($db->sql->insert('t')->values(['name' => 'c'])));
        $stillOpen = $this->pdo->inTransaction();
        $this->pdo->rollBack();

        static::assertSame([true, 2], [$stillOpen, $this->rowCount()]);
    }

    #[Test]
    public function insertReturnsTheNewId(): void
    {
        $db = $this->database();

        static::assertSame(3, $db->insert($db->sql->insert('t')->values(['name' => 'c'])));
    }

    #[Test]
    public function readsARowThatIsNotAnArrayAsNoColumns(): void
    {
        $db = $this->database(result: new ObjectRowResult());

        static::assertSame([[]], $db->rows($db->sql->select('t')->where(['id' => 1])));
    }

    #[Test]
    public function readsOnlyNamedColumnsWhateverTheFetchMode(): void
    {
        $result = new Result();
        $result->setFetchMode(PDO::FETCH_BOTH);
        $db = $this->database(result: $result);

        static::assertSame([['id' => 1, 'name' => 'a']], $db->rows($db->sql->select('t')->where(['id' => 1])));
    }

    #[Test]
    public function readsRowsByColumnName(): void
    {
        $db = $this->database();

        static::assertSame(
            [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']],
            $db->rows($db->sql->select('t')->order('id ASC')),
        );
    }

    #[Test]
    public function readsTheFirstColumnOfEachRow(): void
    {
        $db = $this->database();

        static::assertSame(['a', 'b'], $db->column($db->sql->select('t')->columns(['name'])->order('id ASC')));
    }

    #[Test]
    public function readsTheFirstRowOrNone(): void
    {
        $db = $this->database();

        static::assertSame(
            [['id' => 2, 'name' => 'b'], null],
            [
                $db->row($db->sql->select('t')->where(['name' => 'b'])),
                $db->row($db->sql->select('t')->where(['name' => 'z'])),
            ],
        );
    }

    private function adapter(?NullResultStatement $statement = null, ?Result $result = null): Adapter
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        $this->pdo->exec("INSERT INTO t (name) VALUES ('a'), ('b')");
        $driver = new Driver(
            connection: new Connection($this->pdo),
            statementPrototype: $statement ?? new Statement(),
            resultPrototype: $result ?? new Result(),
        );

        return new Adapter($driver, new AdapterPlatform($driver));
    }

    private function database(?NullResultStatement $statement = null, ?Result $result = null): Database
    {
        return new Database($this->adapter($statement, $result));
    }

    private function rowCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM t')?->fetchColumn();
    }
}
