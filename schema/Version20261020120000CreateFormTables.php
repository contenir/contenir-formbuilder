<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Schema;

use Override;
use PhpDb\Migration\AbstractMigration;
use UnexpectedValueException;

/**
 * The baseline: the nine form_* tables the CMS's "Set up Forms" installer
 * created, with the same names, columns and keys.
 *
 * A table that already exists is left exactly as it is, indexes and all, so
 * on a site where Forms was set up this migration changes nothing and is
 * only recorded as run.
 *
 * @internal Run by the host's migration runner.
 *
 * @mago-expect analysis:missing-constructor AbstractMigration sets its adapter and inspector in up() and preview().
 */
final class Version20261020120000CreateFormTables extends AbstractMigration
{
    #[Override]
    public function getDescription(): string
    {
        return (
            'Create the form builder tables (form, form_section, form_group, form_row, form_field, '
                . 'form_notification, form_webhook, form_entry, form_entry_value)'
        );
    }

    #[Override]
    public function getVersion(): string
    {
        return '20261020120000';
    }

    /**
     * @throws UnexpectedValueException On a database other than MySQL or SQLite.
     */
    #[Override]
    protected function define(): void
    {
        $tables = Platform::isMysql($this->adapter) ? FormTables::MYSQL : FormTables::SQLITE;

        foreach ($tables as $table => $statements) {
            if ($this->inspector->tableExists($table)) {
                $this->skippedOperations[] = "Table \"{$table}\" already exists";

                continue;
            }

            foreach ($statements as $statement) {
                $this->executeSql($statement);
            }
        }
    }
}
