<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Delivery;

use Contenir\FormBuilder\Delivery\WebhookRequest;
use Contenir\FormBuilder\Tests\TestAsset\Delivery\MessageFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

#[Group('unit')]
final class WebhookRequestTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function missingProvider(): array
    {
        return [
            'form'   => ['form'],
            'name'   => ['name'],
            'method' => ['method'],
            'url'    => ['url'],
            'body'   => ['body'],
        ];
    }

    #[Test]
    public function dropsAHeaderThatIsNotAString(): void
    {
        static::assertSame(
            ['X-A: 1'],
            WebhookRequest::fromArray([
                ...MessageFactory::webhook()->toArray(),
                'headers' => ['X-A: 1', ['x']],
            ])?->headers,
        );
    }

    #[Test]
    #[DataProvider('missingProvider')]
    public function refusesDataMissingAPart(string $key): void
    {
        static::assertNull(WebhookRequest::fromArray([...MessageFactory::webhook()->toArray(), $key => null]));
    }

    #[Test]
    public function survivesAQueueAsJson(): void
    {
        $request = MessageFactory::webhook();
        $queued  = json_decode((string) json_encode($request->toArray()), associative: true);

        static::assertEquals($request, WebhookRequest::fromArray((array) $queued));
    }
}
