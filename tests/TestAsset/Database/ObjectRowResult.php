<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\TestAsset\Database;

use Override;
use PhpDb\Adapter\Driver\Pdo\Result;
use ReturnTypeWillChange;

/**
 * A result whose rows are objects, as a driver set to fetch objects returns
 * them.
 */
final class ObjectRowResult extends Result
{
    #[ReturnTypeWillChange]
    #[Override]
    public function current(): mixed
    {
        $row = parent::current();

        return false === $row ? false : (object) $row;
    }
}
