<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Registrar;

use ArrayObject;
use Contenir\FormBuilder\Definition\WebhookDefinition;
use Contenir\FormBuilder\Delivery\WebhookRequest;
use Contenir\FormBuilder\Registrar\WebhookRegistrar;
use Contenir\FormBuilder\Service\BuilderForm;
use Contenir\FormBuilder\Tests\TestAsset\Delivery\RecordingDelivery;
use Contenir\FormBuilder\Tests\TestAsset\Factory\FormDefinitionFactory as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SplObserver;
use SplSubject;

use function bin2hex;
use function hash_hmac;
use function random_bytes;

/**
 * The cases here return before any request is made; dispatch is covered by
 * the integration test against a local HTTP server.
 */
#[Group('unit')]
final class WebhookRegistrarTest extends TestCase
{
    /**
     * @return array<string, array{SplSubject}>
     */
    public static function subjectsWithoutDispatchProvider(): array
    {
        $unreachable = [new WebhookDefinition(
            id: 1,
            name: 'Hook',
            url: 'http://192.0.2.1/never',
        )];

        return [
            'not a builder form' => [new class implements SplSubject {
                public function attach(SplObserver $observer): void {}

                public function detach(SplObserver $observer): void {}

                public function notify(): void {}
            }],
            'no registry'        => [new BuilderForm()],
            'no form definition' => [self::form(['form' => 'contact'])],
            'spam submission'    => [self::form(['form' => F::form(webhooks: $unreachable), 'spam' => true])],
            'no webhooks'        => [self::form(['form' => F::form()])],
        ];
    }

    /**
     * @param array<string, mixed> $registry
     */
    private static function form(array $registry): BuilderForm
    {
        $form           = new BuilderForm();
        $form->registry = new ArrayObject($registry);

        return $form;
    }

    #[Test]
    #[DataProvider('subjectsWithoutDispatchProvider')]
    public function doesNothingWithoutALegitimateSubmissionToSend(SplSubject $subject): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        (new WebhookRegistrar($logger, timeoutSeconds: 1))->update($subject);
    }

    #[Test]
    public function handsEachSignedCallToTheDeliveryGiven(): void
    {
        $delivery = new RecordingDelivery();
        $secret   = bin2hex(random_bytes(8));

        (new WebhookRegistrar(delivery: $delivery))->update(self::form([
            'form'   => F::form(webhooks: [
                new WebhookDefinition(
                    id: 1,
                    name: 'Off',
                    url: 'https://off.example.com',
                    enabled: false,
                ),
                new WebhookDefinition(
                    id: 2,
                    name: 'Plain',
                    url: 'ftp://example.com',
                ),
                new WebhookDefinition(
                    id: 3,
                    name: 'CRM',
                    url: ' https://crm.example.com/hook ',
                    method: '',
                    secret: $secret,
                    headers: ['X-Key' => 'abc'],
                ),
            ]),
            'values' => ['name' => 'Ann'],
            'entry'  => ['id' => 7],
        ]));

        $body = '{"form":{"id":1,"slug":"contact","title":"Contact"},"entry":{"id":7},"values":{"name":"Ann"}}';
        static::assertEquals(
            [new WebhookRequest(
                form: 'contact',
                name: 'CRM',
                method: 'POST',
                url: 'https://crm.example.com/hook',
                headers: [
                    'Content-Type: application/json',
                    'User-Agent: Contenir-FormBuilder-Webhook/1.0',
                    'X-Key: abc',
                    'X-Contenir-Signature: sha256=' . hash_hmac('sha256', $body, key: $secret),
                ],
                body: $body,
            )],
            $delivery->webhooks,
        );
    }
}
