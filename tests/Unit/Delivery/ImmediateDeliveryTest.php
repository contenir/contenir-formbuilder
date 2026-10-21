<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Delivery;

use Contenir\FormBuilder\Delivery\ImmediateDelivery;
use Contenir\FormBuilder\Delivery\MailTransportInterface;
use Contenir\FormBuilder\Delivery\WebhookResponse;
use Contenir\FormBuilder\Delivery\WebhookTransportInterface;
use Contenir\FormBuilder\Tests\TestAsset\Delivery\MessageFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[Group('unit')]
final class ImmediateDeliveryTest extends TestCase
{
    #[Test]
    public function failsQuietlyWithoutALogger(): void
    {
        $transport = static::createStub(WebhookTransportInterface::class);
        $transport->method('send')->willReturn(new WebhookResponse(0, 'timeout'));
        $delivery = new ImmediateDelivery(webhooks: $transport);

        $delivery->webhook(MessageFactory::webhook());
        $delivery->email(MessageFactory::email());

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function logsAFailedWebhook(): void
    {
        $transport = static::createStub(WebhookTransportInterface::class);
        $transport->method('send')->willReturn(new WebhookResponse(503));
        $log = $this->createMock(LoggerInterface::class);
        $log->expects($this->once())
            ->method('warning')
            ->with('Webhook "CRM" for form "contact" failed (status 503): HTTP 503');

        (new ImmediateDelivery(
            webhooks: $transport,
            log: $log,
        ))->webhook(MessageFactory::webhook());
    }

    #[Test]
    public function logsAnEmailItHasNoTransportFor(): void
    {
        $log = $this->createMock(LoggerInterface::class);
        $log->expects($this->once())
            ->method('warning')
            ->with('Form notification "Staff" failed for form "contact": no mail transport is configured');

        (new ImmediateDelivery(log: $log))->email(MessageFactory::email());
    }

    #[Test]
    public function logsAnEmailTheTransportCouldNotSendWithoutThrowing(): void
    {
        $mail = static::createStub(MailTransportInterface::class);
        $mail->method('send')->willThrowException(new RuntimeException('Connection refused'));
        $log = $this->createMock(LoggerInterface::class);
        $log->expects($this->once())
            ->method('warning')
            ->with('Form notification "Staff" failed for form "contact": Connection refused');
        $log->expects($this->never())->method('info');

        (new ImmediateDelivery($mail, log: $log))->email(MessageFactory::email());
    }

    #[Test]
    public function sendsAnEmailThroughTheMailTransport(): void
    {
        $message = MessageFactory::email();
        $mail    = $this->createMock(MailTransportInterface::class);
        $mail->expects($this->once())->method('send')->with($message);
        $log = $this->createMock(LoggerInterface::class);
        $log->expects($this->never())->method('warning');
        $log->expects($this->once())->method('info')->with('Sent form notification "Staff" for form "contact"');

        (new ImmediateDelivery($mail, log: $log))->email($message);
    }

    #[Test]
    public function sendsAWebhookAndLogsThatItWasSent(): void
    {
        $request   = MessageFactory::webhook();
        $transport = $this->createMock(WebhookTransportInterface::class);
        $transport->expects($this->once())->method('send')->with($request)->willReturn(new WebhookResponse(204));
        $log = $this->createMock(LoggerInterface::class);
        $log->expects($this->never())->method('warning');
        $log->expects($this->once())->method('info')->with('Sent webhook "CRM" for form "contact" (status 204)');

        (new ImmediateDelivery(
            webhooks: $transport,
            log: $log,
        ))->webhook($request);
    }
}
