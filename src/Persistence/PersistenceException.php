<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Persistence;

use RuntimeException;
use Throwable;

/**
 * The database refused or failed a read or write of the form builder's
 * tables, or returned a value that cannot be read as the column it is.
 *
 * @api
 */
final class PersistenceException extends RuntimeException
{
    public static function failed(Throwable $previous): self
    {
        return new self("The form builder's database failed: {$previous->getMessage()}", 0, $previous);
    }

    public static function noResult(): self
    {
        return new self("The form builder's database returned no result");
    }

    public static function unreadable(string $column, string $expected): self
    {
        return new self("Column \"{$column}\" does not hold {$expected}");
    }
}
