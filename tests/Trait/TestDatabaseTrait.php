<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Trait;

use Contenir\FormBuilder\Tests\TestAsset\Database\TestDatabase;
use PhpDb\Adapter\AdapterInterface;

/**
 * A database with the form builder's tables freshly migrated for each test,
 * and dropped again afterwards. Call setUpDatabase() from setUp() and
 * tearDownDatabase() from tearDown().
 */
trait TestDatabaseTrait
{
    private AdapterInterface $adapter;

    private bool $connected = false;

    private function setUpDatabase(): void
    {
        $this->adapter   = TestDatabase::connect();
        $this->connected = true;
        TestDatabase::drop($this->adapter);
        $runner = TestDatabase::migrations($this->adapter);
        $runner->ensureMigrationsTable();
        $runner->runPending();
    }

    /**
     * Drops the tables again, unless setUpDatabase() skipped the test before
     * it connected.
     */
    private function tearDownDatabase(): void
    {
        if ($this->connected) {
            TestDatabase::drop($this->adapter);
        }
    }
}
