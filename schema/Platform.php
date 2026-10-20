<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Schema;

use PhpDb\Adapter\AdapterInterface;
use UnexpectedValueException;

/**
 * Which SQL dialect a migration writes. The form builder's tables live on
 * MySQL (the site database) or SQLite; anything else is refused before a
 * statement runs.
 *
 * @internal
 */
final class Platform
{
    /**
     * @throws UnexpectedValueException On a database other than MySQL or SQLite.
     */
    public static function isMysql(AdapterInterface $adapter): bool
    {
        return match ($adapter->getPlatform()->getName()) {
            'MySQL'  => true,
            'SQLite' => false,
            default  => throw new UnexpectedValueException(
                "contenir/formbuilder supports MySQL and SQLite, not {$adapter->getPlatform()->getName()}",
            ),
        };
    }
}
