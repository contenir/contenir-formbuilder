<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit;

use Contenir\FormBuilder\ConfigProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function realpath;

#[Group('unit')]
final class ConfigProviderTest extends TestCase
{
    #[Test]
    public function listsTheMigrationsForTheHost(): void
    {
        static::assertSame(
            ['contenir' => ['schema_migrations' => [ConfigProvider::SCHEMA_NAMESPACE => ConfigProvider::schemaPath()]]],
            (new ConfigProvider())(),
        );
    }

    #[Test]
    public function pointsAtTheSchemaDirectory(): void
    {
        static::assertSame(realpath(dirname(__DIR__, levels: 2) . '/schema'), realpath(ConfigProvider::schemaPath()));
    }
}
