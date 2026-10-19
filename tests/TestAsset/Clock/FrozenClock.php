<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\TestAsset\Clock;

use DateTimeImmutable;
use Override;
use Psr\Clock\ClockInterface;

/**
 * A clock that always reads the time it was given.
 */
final class FrozenClock implements ClockInterface
{
    public function __construct(
        private readonly DateTimeImmutable $now,
    ) {}

    #[Override]
    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
