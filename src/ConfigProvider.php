<?php

declare(strict_types=1);

namespace Contenir\FormBuilder;

use function dirname;

/**
 * Tells a host where the form builder's migrations are.
 *
 * The form_* tables are created by the migrations in `schema/`, under
 * {@see SCHEMA_NAMESPACE}, for a host that runs them with
 * peptolab/phpdb-migration; they are listed under
 * `contenir.schema_migrations` for a host that collects them from its
 * configuration.
 *
 * @api
 */
final class ConfigProvider
{
    public const string SCHEMA_NAMESPACE = __NAMESPACE__ . '\\Schema';

    /**
     * The directory of the form builder's migrations.
     */
    public static function schemaPath(): string
    {
        return dirname(__DIR__) . '/schema';
    }

    /**
     * @return array{contenir: array{schema_migrations: array<string, string>}}
     */
    public function __invoke(): array
    {
        return [
            'contenir' => [
                'schema_migrations' => [
                    self::SCHEMA_NAMESPACE => self::schemaPath(),
                ],
            ],
        ];
    }
}
