<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Registrar;

use ArrayObject;
use Contenir\FormBuilder\Definition\WebhookDefinition;
use Contenir\FormBuilder\Registrar\WebhookRegistrar;
use Contenir\FormBuilder\Service\BuilderForm;
use Contenir\FormBuilder\Tests\TestAsset\Factory\FormDefinitionFactory as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SplObserver;
use SplSubject;

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
}
