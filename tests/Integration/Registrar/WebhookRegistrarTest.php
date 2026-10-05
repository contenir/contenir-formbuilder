<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Integration\Registrar;

use ArrayObject;
use Contenir\FormBuilder\Definition\WebhookDefinition;
use Contenir\FormBuilder\Registrar\WebhookRegistrar;
use Contenir\FormBuilder\Service\BuilderForm;
use Contenir\FormBuilder\Tests\TestAsset\Factory\FormDefinitionFactory as F;
use Contenir\FormBuilder\Tests\Trait\TemporaryDirectoryTrait;
use Contenir\FormBuilder\Tests\Trait\WebhookServerTrait;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

use function bin2hex;
use function hash_hmac;
use function json_decode;
use function random_bytes;

/**
 * Posts real HTTP requests to PHP's built-in server on the loopback interface.
 */
#[Group('integration')]
#[Group('registrar')]
#[Group('slow')]
final class WebhookRegistrarTest extends TestCase
{
    use TemporaryDirectoryTrait;
    use WebhookServerTrait;

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unusablePayloadProvider(): array
    {
        return [
            'missing values and entry'   => [[]],
            'non-array values and entry' => [['values' => 'x', 'entry' => 'y']],
        ];
    }

    #[Test]
    public function emptyMethodFallsBackToPost(): void
    {
        $webhook = new WebhookDefinition(
            id: 1,
            name: 'CRM',
            url: "{$this->serverUrl}/200",
            method: '',
        );

        (new WebhookRegistrar())->update($this->submission([$webhook]));

        static::assertSame('POST', $this->receivedRequests()[0]['method']);
    }

    #[Test]
    public function failureWithoutALoggerIsSilent(): void
    {
        (new WebhookRegistrar())->update($this->submission([
            new WebhookDefinition(
                id: 1,
                name: 'CRM',
                url: "{$this->serverUrl}/500",
            ),
        ]));

        static::assertCount(1, $this->receivedRequests());
    }

    #[Test]
    public function logsAConnectionFailureWithoutThrowing(): void
    {
        $this->tearDownWebhookServer();
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringStartsWith('Webhook "CRM" for form "contact" failed (status 0): '));

        (new WebhookRegistrar($logger, timeoutSeconds: 2))->update($this->submission([
            new WebhookDefinition(
                id: 1,
                name: 'CRM',
                url: "{$this->serverUrl}/200",
            ),
        ]));
    }

    #[Test]
    public function logsAnErrorStatusWithoutThrowing(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Webhook "CRM" for form "contact" failed (status 500): HTTP 500');

        (new WebhookRegistrar($logger))->update($this->submission([
            new WebhookDefinition(
                id: 1,
                name: 'CRM',
                url: "{$this->serverUrl}/500",
            ),
        ]));
    }

    #[Test]
    public function postsTheSubmissionAsJsonWithHeadersAndSignature(): void
    {
        $secret = bin2hex(random_bytes(8));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');
        $webhook = new WebhookDefinition(
            id: 1,
            name: 'CRM',
            url: " {$this->serverUrl}/200 ",
            secret: $secret,
            headers: ['X-Api-Key' => 'abc'],
        );

        (new WebhookRegistrar($logger))->update($this->submission([$webhook]));

        $requests = $this->receivedRequests();
        static::assertCount(1, $requests);
        $request = $requests[0];
        static::assertSame('POST', $request['method']);
        static::assertSame(
            [
                'form'   => ['id' => 1, 'slug' => 'contact', 'title' => 'Contact'],
                'entry'  => ['id' => 7],
                'values' => ['name' => 'Ann/Ünïcode'],
            ],
            json_decode($request['body'], associative: true),
        );
        static::assertSame(
            '{"form":{"id":1,"slug":"contact","title":"Contact"},"entry":{"id":7},"values":{"name":"Ann/Ünïcode"}}',
            $request['body'],
        );
        static::assertSame('application/json', $request['headers']['Content-Type']);
        static::assertSame('Contenir-FormBuilder-Webhook/1.0', $request['headers']['User-Agent']);
        static::assertSame('abc', $request['headers']['X-Api-Key']);
        static::assertSame(
            'sha256=' . hash_hmac('sha256', $request['body'], key: $secret),
            $request['headers']['X-Contenir-Signature'],
        );
    }

    #[Test]
    public function skipsDisabledWebhooksAndNonHttpUrls(): void
    {
        (new WebhookRegistrar())->update($this->submission([
            new WebhookDefinition(
                id: 1,
                name: 'Off',
                url: "{$this->serverUrl}/200",
                enabled: false,
            ),
            new WebhookDefinition(
                id: 2,
                name: 'Blank',
                url: '  ',
            ),
            new WebhookDefinition(
                id: 3,
                name: 'File',
                url: '/etc/passwd',
            ),
            new WebhookDefinition(
                id: 4,
                name: 'On',
                url: "{$this->serverUrl}/201",
            ),
        ]));

        $requests = $this->receivedRequests();
        static::assertCount(1, $requests);
        static::assertSame('/201', $requests[0]['uri']);
    }

    /**
     * @param array<string, mixed> $registry
     */
    #[Test]
    #[DataProvider('unusablePayloadProvider')]
    public function unusableValuesAndEntryAreSentEmpty(array $registry): void
    {
        $form           = new BuilderForm();
        $form->registry = new ArrayObject([
            ...$registry,
            'form' => F::form(webhooks: [new WebhookDefinition(
                id: 1,
                name: 'CRM',
                url: "{$this->serverUrl}/200",
            )]),
        ]);

        (new WebhookRegistrar())->update($form);

        static::assertSame(
            '{"form":{"id":1,"slug":"contact","title":"Contact"},"entry":[],"values":[]}',
            $this->receivedRequests()[0]['body'],
        );
    }

    #[Test]
    public function usesTheConfiguredMethodAndOmitsTheSignatureWithoutASecret(): void
    {
        $webhook = new WebhookDefinition(
            id: 1,
            name: 'CRM',
            url: "{$this->serverUrl}/204",
            method: 'PUT',
            secret: '',
        );

        (new WebhookRegistrar())->update($this->submission([$webhook]));

        $request = $this->receivedRequests()[0];
        static::assertSame('PUT', $request['method']);
        static::assertArrayNotHasKey('X-Contenir-Signature', $request['headers']);
    }

    #[Override]
    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->setUpWebhookServer();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->tearDownWebhookServer();
        $this->tearDownTemporaryDirectory();
    }

    /**
     * @param list<WebhookDefinition> $webhooks
     */
    private function submission(array $webhooks): BuilderForm
    {
        $form           = new BuilderForm();
        $form->registry = new ArrayObject([
            'form'   => F::form(webhooks: $webhooks),
            'values' => ['name' => 'Ann/Ünïcode'],
            'entry'  => ['id' => 7],
            'spam'   => false,
        ]);

        return $form;
    }
}
