<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\TestAsset\Delivery;

use Contenir\FormBuilder\Delivery\DeliveryInterface;
use Contenir\FormBuilder\Delivery\EmailMessage;
use Contenir\FormBuilder\Delivery\WebhookRequest;
use Override;

/**
 * Keeps every email and webhook call handed to it, sending none.
 */
final class RecordingDelivery implements DeliveryInterface
{
    /** @var list<EmailMessage> */
    public array $emails = [];

    /** @var list<WebhookRequest> */
    public array $webhooks = [];

    #[Override]
    public function email(EmailMessage $message): void
    {
        $this->emails[] = $message;
    }

    #[Override]
    public function webhook(WebhookRequest $request): void
    {
        $this->webhooks[] = $request;
    }
}
