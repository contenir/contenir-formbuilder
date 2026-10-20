<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Delivery;

use Contenir\FormBuilder\Delivery\WebhookResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class WebhookResponseTest extends TestCase
{
    /**
     * @return array<string, array{WebhookResponse, bool, string}>
     */
    public static function responseProvider(): array
    {
        return [
            'success'            => [new WebhookResponse(200), false, 'HTTP 200'],
            'redirect not taken' => [new WebhookResponse(399), false, 'HTTP 399'],
            'client error'       => [new WebhookResponse(400), true, 'HTTP 400'],
            'server error'       => [new WebhookResponse(500), true, 'HTTP 500'],
            'no response'        => [new WebhookResponse(0, 'Could not resolve host'), true, 'Could not resolve host'],
        ];
    }

    #[Test]
    #[DataProvider('responseProvider')]
    public function saysWhetherAndWhyTheCallFailed(WebhookResponse $response, bool $failed, string $reason): void
    {
        static::assertSame([$failed, $reason], [$response->failed(), $response->reason()]);
    }
}
