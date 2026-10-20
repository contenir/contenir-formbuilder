<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\TestAsset\Database;

use Contenir\FormBuilder\ConfigProvider;
use PDO;
use PDOException;
use PhpDb\Adapter\Adapter;
use PhpDb\Adapter\AdapterInterface;
use PhpDb\Metadata\MetadataInterface;
use PhpDb\Migration\MigrationRunner;
use PhpDb\Migration\MismatchStrategy;
use PhpDb\Migration\SchemaInspector;
use PhpDb\Mysql;
use PhpDb\Sqlite;
use PHPUnit\Framework\SkippedWithMessageException;

use function array_reverse;
use function explode;
use function file_get_contents;
use function getenv;
use function is_string;
use function sprintf;
use function trim;

/**
 * The database integration tests run against: SQLite in memory by default,
 * or MySQL when CONTENIR_TEST_DB_PLATFORM is "mysql" (connection settings in
 * CONTENIR_TEST_DB_HOST, _PORT, _NAME, _USER and _PASSWORD).
 *
 * SQLite runs with foreign keys on, as the CMS's installer turned them on, so
 * deletes cascade on both platforms, and returns the rows of any select
 * without an ORDER BY backwards, so a test relying on an order the code does
 * not ask for fails.
 */
final class TestDatabase
{
    /**
     * Every table the form builder's migration creates, parents first, then
     * the migration runner's own.
     */
    public const array TABLES = [
        'form',
        'form_section',
        'form_group',
        'form_row',
        'form_field',
        'form_notification',
        'form_webhook',
        'form_entry',
        'form_entry_value',
        'migrations',
    ];

    public static function connect(): AdapterInterface
    {
        return self::isMysql() ? self::mysql() : self::sqlite();
    }

    /**
     * Drops every table, children first.
     */
    public static function drop(AdapterInterface $adapter): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            $adapter->query("DROP TABLE IF EXISTS {$table}", Adapter::QUERY_MODE_EXECUTE);
        }
    }

    public static function inspector(AdapterInterface $adapter): SchemaInspector
    {
        return new SchemaInspector($adapter, self::metadata($adapter));
    }

    /**
     * Creates the tables as the CMS's "Set up Forms" installer did, from its
     * own SQL, before any migration ran.
     */
    public static function installAsTheCmsDid(AdapterInterface $adapter): void
    {
        $file = __DIR__ . '/../Fixture/install-forms.' . (self::isMysql() ? 'mysql' : 'sqlite') . '.sql';
        foreach (explode(';', (string) file_get_contents($file)) as $statement) {
            if ('' === trim($statement)) {
                continue;
            }
            $adapter->query($statement, Adapter::QUERY_MODE_EXECUTE);
        }
    }

    /**
     * On SQLite, whether a select without an ORDER BY returns its rows
     * backwards (as the tests' connections do) or in the order SQLite reads
     * them. MySQL is left as it is.
     */
    public static function reverseUnorderedSelects(AdapterInterface $adapter, bool $reverse): void
    {
        if (self::isMysql()) {
            return;
        }
        $adapter->query(
            'PRAGMA reverse_unordered_selects = ' . ($reverse ? 'ON' : 'OFF'),
            Adapter::QUERY_MODE_EXECUTE,
        );
    }

    public static function isMysql(): bool
    {
        return 'mysql' === self::env('PLATFORM', 'sqlite');
    }

    public static function metadata(AdapterInterface $adapter): MetadataInterface
    {
        return 'MySQL' === $adapter->getPlatform()->getName()
            ? new Mysql\Metadata\Source($adapter)
            : new Sqlite\Metadata\Source($adapter);
    }

    public static function migrations(AdapterInterface $adapter): MigrationRunner
    {
        return new MigrationRunner(
            $adapter,
            ConfigProvider::schemaPath(),
            ConfigProvider::SCHEMA_NAMESPACE,
            MismatchStrategy::Report,
            self::metadata($adapter),
        );
    }

    /**
     * Turns foreign key checks, and so cascading deletes, off for the
     * connection.
     */
    public static function withoutForeignKeys(AdapterInterface $adapter): void
    {
        $adapter->query(
            self::isMysql() ? 'SET FOREIGN_KEY_CHECKS = 0' : 'PRAGMA foreign_keys = OFF',
            Adapter::QUERY_MODE_EXECUTE,
        );
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv("CONTENIR_TEST_DB_{$name}");

        return is_string($value) && '' !== $value ? $value : $default;
    }

    private static function mysql(): AdapterInterface
    {
        try {
            $pdo = new PDO(
                sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    self::env('HOST', '127.0.0.1'),
                    self::env('PORT', '3306'),
                    self::env('NAME', 'contenir_formbuilder_test'),
                ),
                self::env('USER', 'contenir'),
                self::env('PASSWORD', 'contenir'),
            );
        } catch (PDOException $e) {
            throw new SkippedWithMessageException("MySQL is not reachable: {$e->getMessage()}");
        }

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $driver = new Mysql\Pdo\Driver(new Mysql\Pdo\Connection($pdo));

        return new Adapter($driver, new Mysql\AdapterPlatform($driver));
    }

    private static function sqlite(): AdapterInterface
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA reverse_unordered_selects = ON');
        $driver = new Sqlite\Pdo\Driver(connection: new Sqlite\Pdo\Connection($pdo));

        return new Adapter($driver, new Sqlite\AdapterPlatform($driver));
    }
}
