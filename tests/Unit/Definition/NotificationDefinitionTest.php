<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Definition;

use Contenir\FormBuilder\Definition\NotificationDefinition;
use Contenir\FormBuilder\Definition\WebhookDefinition;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class NotificationDefinitionTest extends TestCase
{
    #[Test]
    public function notificationDefaultsToAnEnabledSubmitTrigger(): void
    {
        $notification = new NotificationDefinition(
            id: 1,
            name: 'Admin',
        );

        static::assertSame(
            ['submit', '', null, null, '', null, null, true, 0],
            [
                $notification->trigger,
                $notification->toAddress,
                $notification->fromAddress,
                $notification->replyTo,
                $notification->subject,
                $notification->bodyTemplate,
                $notification->conditions,
                $notification->enabled,
                $notification->sort,
            ],
        );
    }

    #[Test]
    public function webhookDefaultsToAnEnabledUnsignedPost(): void
    {
        $webhook = new WebhookDefinition(
            id: 1,
            name: 'CRM',
            url: 'https://crm.example/hook',
        );

        static::assertSame(
            ['POST', null, [], true, 0],
            [$webhook->method, $webhook->secret, $webhook->headers, $webhook->enabled, $webhook->sort],
        );
    }
}
