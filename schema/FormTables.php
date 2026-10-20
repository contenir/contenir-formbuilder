<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Schema;

/**
 * The form builder's tables as the CMS installer created them, statement for
 * statement, in MySQL and SQLite: the names, columns, keys and foreign keys
 * are kept exactly, because public sites read these tables directly.
 *
 * Each table's statements are listed in the order they must run, and the
 * tables in an order that creates every table before one that refers to it.
 *
 * @internal
 */
final class FormTables
{
    /**
     * @var non-empty-array<non-empty-string, non-empty-list<non-empty-string>>
     */
    public const array MYSQL = [
        'form'              => [
            'CREATE TABLE IF NOT EXISTS `form` ('
                . ' `form_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . ' `slug` VARCHAR(64) NOT NULL,'
                . ' `title` VARCHAR(255) NOT NULL,'
                . ' `description` TEXT NULL,'
                . ' `layout_mode` VARCHAR(16) NOT NULL DEFAULT \'single\','
                . ' `submit_label` VARCHAR(64) NOT NULL DEFAULT \'Submit\','
                . ' `submit_alignment` VARCHAR(16) NOT NULL DEFAULT \'left\','
                . ' `settings_json` TEXT NULL,'
                . ' `retention_days` INT UNSIGNED NULL,'
                . ' `status` VARCHAR(16) NOT NULL DEFAULT \'active\','
                . ' `created` DATETIME NULL,'
                . ' `updated` DATETIME NULL,'
                . ' PRIMARY KEY (`form_id`),'
                . ' UNIQUE KEY `form_slug` (`slug`)'
                . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'form_section'      => [
            'CREATE TABLE IF NOT EXISTS `form_section` ('
                . ' `form_section_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . ' `form_id` INT UNSIGNED NOT NULL,'
                . ' `key` VARCHAR(64) NOT NULL,'
                . ' `legend` VARCHAR(255) NULL,'
                . ' `description` TEXT NULL,'
                . ' `sort` INT NOT NULL DEFAULT 0,'
                . ' PRIMARY KEY (`form_section_id`),'
                . ' UNIQUE KEY `form_section_form_key` (`form_id`, `key`),'
                . ' KEY `form_section_form_id` (`form_id`),'
                . ' CONSTRAINT `fk_form_section_form` FOREIGN KEY (`form_id`) REFERENCES `form` (`form_id`) ON DELETE CASCADE ON UPDATE CASCADE'
                . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'form_group'        => [
            'CREATE TABLE IF NOT EXISTS `form_group` ('
                . ' `form_group_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . ' `form_section_id` INT UNSIGNED NOT NULL,'
                . ' `legend` VARCHAR(255) NULL,'
                . ' `description` TEXT NULL,'
                . ' `sort` INT NOT NULL DEFAULT 0,'
                . ' PRIMARY KEY (`form_group_id`),'
                . ' KEY `form_group_section_id` (`form_section_id`),'
                . ' CONSTRAINT `fk_form_group_section` FOREIGN KEY (`form_section_id`) REFERENCES `form_section` (`form_section_id`) ON DELETE CASCADE ON UPDATE CASCADE'
                . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'form_row'          => [
            'CREATE TABLE IF NOT EXISTS `form_row` ('
                . ' `form_row_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . ' `form_group_id` INT UNSIGNED NOT NULL,'
                . ' `sort` INT NOT NULL DEFAULT 0,'
                . ' PRIMARY KEY (`form_row_id`),'
                . ' KEY `form_row_group_id` (`form_group_id`),'
                . ' CONSTRAINT `fk_form_row_group` FOREIGN KEY (`form_group_id`) REFERENCES `form_group` (`form_group_id`) ON DELETE CASCADE ON UPDATE CASCADE'
                . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'form_field'        => [
            'CREATE TABLE IF NOT EXISTS `form_field` ('
                . ' `form_field_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . ' `form_row_id` INT UNSIGNED NOT NULL,'
                . ' `type` VARCHAR(32) NOT NULL,'
                . ' `name` VARCHAR(64) NOT NULL,'
                . ' `label` VARCHAR(255) NULL,'
                . ' `show_label` TINYINT(1) NOT NULL DEFAULT 1,'
                . ' `description` TEXT NULL,'
                . ' `placeholder` VARCHAR(255) NULL,'
                . ' `default_value` TEXT NULL,'
                . ' `required` TINYINT(1) NOT NULL DEFAULT 0,'
                . ' `col_span` TINYINT NOT NULL DEFAULT 4,'
                . ' `sort` INT NOT NULL DEFAULT 0,'
                . ' `options_json` TEXT NULL,'
                . ' `validators_json` TEXT NULL,'
                . ' `filters_json` TEXT NULL,'
                . ' `conditional_json` TEXT NULL,'
                . ' PRIMARY KEY (`form_field_id`),'
                . ' KEY `form_field_row_id` (`form_row_id`),'
                . ' CONSTRAINT `fk_form_field_row` FOREIGN KEY (`form_row_id`) REFERENCES `form_row` (`form_row_id`) ON DELETE CASCADE ON UPDATE CASCADE'
                . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'form_notification' => [
            'CREATE TABLE IF NOT EXISTS `form_notification` ('
                . ' `form_notification_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . ' `form_id` INT UNSIGNED NOT NULL,'
                . ' `name` VARCHAR(128) NOT NULL,'
                . ' `trigger` VARCHAR(32) NOT NULL DEFAULT \'submit\','
                . ' `to_address` TEXT NOT NULL,'
                . ' `from_address` VARCHAR(255) NULL,'
                . ' `reply_to` VARCHAR(255) NULL,'
                . ' `subject` VARCHAR(255) NOT NULL,'
                . ' `body_template` TEXT NULL,'
                . ' `conditions_json` TEXT NULL,'
                . ' `enabled` TINYINT(1) NOT NULL DEFAULT 1,'
                . ' `sort` INT NOT NULL DEFAULT 0,'
                . ' PRIMARY KEY (`form_notification_id`),'
                . ' KEY `form_notification_form_id` (`form_id`),'
                . ' CONSTRAINT `fk_form_notification_form` FOREIGN KEY (`form_id`) REFERENCES `form` (`form_id`) ON DELETE CASCADE ON UPDATE CASCADE'
                . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'form_webhook'      => [
            'CREATE TABLE IF NOT EXISTS `form_webhook` ('
                . ' `form_webhook_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . ' `form_id` INT UNSIGNED NOT NULL,'
                . ' `name` VARCHAR(128) NOT NULL,'
                . ' `url` TEXT NOT NULL,'
                . ' `method` VARCHAR(8) NOT NULL DEFAULT \'POST\','
                . ' `secret` VARCHAR(255) NULL,'
                . ' `headers_json` TEXT NULL,'
                . ' `enabled` TINYINT(1) NOT NULL DEFAULT 1,'
                . ' `sort` INT NOT NULL DEFAULT 0,'
                . ' PRIMARY KEY (`form_webhook_id`),'
                . ' KEY `form_webhook_form_id` (`form_id`),'
                . ' CONSTRAINT `fk_form_webhook_form` FOREIGN KEY (`form_id`) REFERENCES `form` (`form_id`) ON DELETE CASCADE ON UPDATE CASCADE'
                . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'form_entry'        => [
            'CREATE TABLE IF NOT EXISTS `form_entry` ('
                . ' `form_entry_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . ' `form_id` INT UNSIGNED NOT NULL,'
                . ' `submitted_at` DATETIME NULL,'
                . ' `ip` VARCHAR(64) NULL,'
                . ' `user_id` INT UNSIGNED NULL,'
                . ' `status` VARCHAR(16) NOT NULL DEFAULT \'pending\','
                . ' `meta_json` TEXT NULL,'
                . ' PRIMARY KEY (`form_entry_id`),'
                . ' KEY `form_entry_form_id` (`form_id`),'
                . ' KEY `form_entry_status` (`status`),'
                . ' KEY `form_entry_submitted_at` (`submitted_at`),'
                . ' CONSTRAINT `fk_form_entry_form` FOREIGN KEY (`form_id`) REFERENCES `form` (`form_id`) ON DELETE CASCADE ON UPDATE CASCADE'
                . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
        'form_entry_value'  => [
            'CREATE TABLE IF NOT EXISTS `form_entry_value` ('
                . ' `form_entry_value_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . ' `form_entry_id` INT UNSIGNED NOT NULL,'
                . ' `form_field_id` INT UNSIGNED NULL,'
                . ' `field_name` VARCHAR(64) NOT NULL,'
                . ' `value_text` TEXT NULL,'
                . ' `value_json` TEXT NULL,'
                . ' PRIMARY KEY (`form_entry_value_id`),'
                . ' KEY `form_entry_value_entry_id` (`form_entry_id`),'
                . ' KEY `form_entry_value_field_name` (`field_name`),'
                . ' CONSTRAINT `fk_form_entry_value_entry` FOREIGN KEY (`form_entry_id`) REFERENCES `form_entry` (`form_entry_id`) ON DELETE CASCADE ON UPDATE CASCADE'
                . ' ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ],
    ];

    /**
     * @var non-empty-array<non-empty-string, non-empty-list<non-empty-string>>
     */
    public const array SQLITE = [
        'form'              => [
            'CREATE TABLE IF NOT EXISTS `form` ('
                . ' `form_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,'
                . ' `slug` VARCHAR(64) NOT NULL,'
                . ' `title` VARCHAR(255) NOT NULL,'
                . ' `description` TEXT NULL,'
                . ' `layout_mode` VARCHAR(16) NOT NULL DEFAULT \'single\','
                . ' `submit_label` VARCHAR(64) NOT NULL DEFAULT \'Submit\','
                . ' `submit_alignment` VARCHAR(16) NOT NULL DEFAULT \'left\','
                . ' `settings_json` TEXT NULL,'
                . ' `retention_days` INTEGER NULL,'
                . ' `status` VARCHAR(16) NOT NULL DEFAULT \'active\','
                . ' `created` DATETIME NULL,'
                . ' `updated` DATETIME NULL'
                . ' )',
            'CREATE UNIQUE INDEX IF NOT EXISTS `form_slug` ON `form` (`slug`)',
        ],
        'form_section'      => [
            'CREATE TABLE IF NOT EXISTS `form_section` ('
                . ' `form_section_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,'
                . ' `form_id` INTEGER NOT NULL,'
                . ' `key` VARCHAR(64) NOT NULL,'
                . ' `legend` VARCHAR(255) NULL,'
                . ' `description` TEXT NULL,'
                . ' `sort` INTEGER NOT NULL DEFAULT 0,'
                . ' FOREIGN KEY (`form_id`) REFERENCES `form` (`form_id`) ON DELETE CASCADE'
                . ' )',
            'CREATE UNIQUE INDEX IF NOT EXISTS `form_section_form_key` ON `form_section` (`form_id`, `key`)',
            'CREATE INDEX IF NOT EXISTS `form_section_form_id` ON `form_section` (`form_id`)',
        ],
        'form_group'        => [
            'CREATE TABLE IF NOT EXISTS `form_group` ('
                . ' `form_group_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,'
                . ' `form_section_id` INTEGER NOT NULL,'
                . ' `legend` VARCHAR(255) NULL,'
                . ' `description` TEXT NULL,'
                . ' `sort` INTEGER NOT NULL DEFAULT 0,'
                . ' FOREIGN KEY (`form_section_id`) REFERENCES `form_section` (`form_section_id`) ON DELETE CASCADE'
                . ' )',
            'CREATE INDEX IF NOT EXISTS `form_group_section_id` ON `form_group` (`form_section_id`)',
        ],
        'form_row'          => [
            'CREATE TABLE IF NOT EXISTS `form_row` ('
                . ' `form_row_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,'
                . ' `form_group_id` INTEGER NOT NULL,'
                . ' `sort` INTEGER NOT NULL DEFAULT 0,'
                . ' FOREIGN KEY (`form_group_id`) REFERENCES `form_group` (`form_group_id`) ON DELETE CASCADE'
                . ' )',
            'CREATE INDEX IF NOT EXISTS `form_row_group_id` ON `form_row` (`form_group_id`)',
        ],
        'form_field'        => [
            'CREATE TABLE IF NOT EXISTS `form_field` ('
                . ' `form_field_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,'
                . ' `form_row_id` INTEGER NOT NULL,'
                . ' `type` VARCHAR(32) NOT NULL,'
                . ' `name` VARCHAR(64) NOT NULL,'
                . ' `label` VARCHAR(255) NULL,'
                . ' `show_label` INTEGER NOT NULL DEFAULT 1,'
                . ' `description` TEXT NULL,'
                . ' `placeholder` VARCHAR(255) NULL,'
                . ' `default_value` TEXT NULL,'
                . ' `required` INTEGER NOT NULL DEFAULT 0,'
                . ' `col_span` INTEGER NOT NULL DEFAULT 4,'
                . ' `sort` INTEGER NOT NULL DEFAULT 0,'
                . ' `options_json` TEXT NULL,'
                . ' `validators_json` TEXT NULL,'
                . ' `filters_json` TEXT NULL,'
                . ' `conditional_json` TEXT NULL,'
                . ' FOREIGN KEY (`form_row_id`) REFERENCES `form_row` (`form_row_id`) ON DELETE CASCADE'
                . ' )',
            'CREATE INDEX IF NOT EXISTS `form_field_row_id` ON `form_field` (`form_row_id`)',
        ],
        'form_notification' => [
            'CREATE TABLE IF NOT EXISTS `form_notification` ('
                . ' `form_notification_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,'
                . ' `form_id` INTEGER NOT NULL,'
                . ' `name` VARCHAR(128) NOT NULL,'
                . ' `trigger` VARCHAR(32) NOT NULL DEFAULT \'submit\','
                . ' `to_address` TEXT NOT NULL,'
                . ' `from_address` VARCHAR(255) NULL,'
                . ' `reply_to` VARCHAR(255) NULL,'
                . ' `subject` VARCHAR(255) NOT NULL,'
                . ' `body_template` TEXT NULL,'
                . ' `conditions_json` TEXT NULL,'
                . ' `enabled` INTEGER NOT NULL DEFAULT 1,'
                . ' `sort` INTEGER NOT NULL DEFAULT 0,'
                . ' FOREIGN KEY (`form_id`) REFERENCES `form` (`form_id`) ON DELETE CASCADE'
                . ' )',
            'CREATE INDEX IF NOT EXISTS `form_notification_form_id` ON `form_notification` (`form_id`)',
        ],
        'form_webhook'      => [
            'CREATE TABLE IF NOT EXISTS `form_webhook` ('
                . ' `form_webhook_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,'
                . ' `form_id` INTEGER NOT NULL,'
                . ' `name` VARCHAR(128) NOT NULL,'
                . ' `url` TEXT NOT NULL,'
                . ' `method` VARCHAR(8) NOT NULL DEFAULT \'POST\','
                . ' `secret` VARCHAR(255) NULL,'
                . ' `headers_json` TEXT NULL,'
                . ' `enabled` INTEGER NOT NULL DEFAULT 1,'
                . ' `sort` INTEGER NOT NULL DEFAULT 0,'
                . ' FOREIGN KEY (`form_id`) REFERENCES `form` (`form_id`) ON DELETE CASCADE'
                . ' )',
            'CREATE INDEX IF NOT EXISTS `form_webhook_form_id` ON `form_webhook` (`form_id`)',
        ],
        'form_entry'        => [
            'CREATE TABLE IF NOT EXISTS `form_entry` ('
                . ' `form_entry_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,'
                . ' `form_id` INTEGER NOT NULL,'
                . ' `submitted_at` DATETIME NULL,'
                . ' `ip` VARCHAR(64) NULL,'
                . ' `user_id` INTEGER NULL,'
                . ' `status` VARCHAR(16) NOT NULL DEFAULT \'pending\','
                . ' `meta_json` TEXT NULL,'
                . ' FOREIGN KEY (`form_id`) REFERENCES `form` (`form_id`) ON DELETE CASCADE'
                . ' )',
            'CREATE INDEX IF NOT EXISTS `form_entry_form_id` ON `form_entry` (`form_id`)',
            'CREATE INDEX IF NOT EXISTS `form_entry_status` ON `form_entry` (`status`)',
            'CREATE INDEX IF NOT EXISTS `form_entry_submitted_at` ON `form_entry` (`submitted_at`)',
        ],
        'form_entry_value'  => [
            'CREATE TABLE IF NOT EXISTS `form_entry_value` ('
                . ' `form_entry_value_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,'
                . ' `form_entry_id` INTEGER NOT NULL,'
                . ' `form_field_id` INTEGER NULL,'
                . ' `field_name` VARCHAR(64) NOT NULL,'
                . ' `value_text` TEXT NULL,'
                . ' `value_json` TEXT NULL,'
                . ' FOREIGN KEY (`form_entry_id`) REFERENCES `form_entry` (`form_entry_id`) ON DELETE CASCADE'
                . ' )',
            'CREATE INDEX IF NOT EXISTS `form_entry_value_entry_id` ON `form_entry_value` (`form_entry_id`)',
            'CREATE INDEX IF NOT EXISTS `form_entry_value_field_name` ON `form_entry_value` (`field_name`)',
        ],
    ];
}
