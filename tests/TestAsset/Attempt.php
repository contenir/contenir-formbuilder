<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\TestAsset;

use Closure;
use Throwable;

/**
 * Runs something expected to fail, so a test can check what it left behind
 * as well as how it failed.
 */
final class Attempt
{
    /**
     * The class of what $work threw, or null when it returned.
     *
     * @param Closure(): mixed $work
     *
     * @return class-string<Throwable>|null
     */
    public static function of(Closure $work): ?string
    {
        try {
            $work();
        } catch (Throwable $e) {
            return $e::class;
        }

        return null;
    }
}
