<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Schema;

use Contenir\FormBuilder\Schema\Platform;
use PhpDb\Adapter\AdapterInterface;
use PhpDb\Adapter\Platform\PlatformInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

#[Group('unit')]
final class PlatformTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function supportedProvider(): array
    {
        return [
            'MySQL'  => ['MySQL', true],
            'SQLite' => ['SQLite', false],
        ];
    }

    #[Test]
    public function refusesAnyOtherDatabase(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('contenir/formbuilder supports MySQL and SQLite, not PostgreSQL');

        Platform::isMysql($this->adapter('PostgreSQL'));
    }

    #[Test]
    #[DataProvider('supportedProvider')]
    public function tellsMysqlFromSqlite(string $name, bool $isMysql): void
    {
        static::assertSame($isMysql, Platform::isMysql($this->adapter($name)));
    }

    private function adapter(string $name): AdapterInterface
    {
        $platform = static::createStub(PlatformInterface::class);
        $platform->method('getName')->willReturn($name);
        $adapter = static::createStub(AdapterInterface::class);
        $adapter->method('getPlatform')->willReturn($platform);

        return $adapter;
    }
}
