<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Clock;

use Contenir\FormBuilder\Clock\SystemClock;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[Group('clock')]
final class SystemClockTest extends TestCase
{
    #[Test]
    public function readsTheCurrentTime(): void
    {
        $before = new DateTimeImmutable();
        $now    = (new SystemClock())->now();
        $after  = new DateTimeImmutable();

        static::assertGreaterThanOrEqual($before, $now);
        static::assertLessThanOrEqual($after, $now);
    }
}
