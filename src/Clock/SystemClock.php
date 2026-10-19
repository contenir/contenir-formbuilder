<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Clock;

use DateTimeImmutable;
use Override;
use Psr\Clock\ClockInterface;

/**
 * The current time in PHP's default time zone. Used when no other PSR-20
 * clock is supplied.
 *
 * @internal
 */
final class SystemClock implements ClockInterface
{
    #[Override]
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
