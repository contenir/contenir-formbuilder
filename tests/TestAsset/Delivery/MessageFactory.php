<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\TestAsset\Delivery;

use Contenir\FormBuilder\Delivery\EmailMessage;
use Contenir\FormBuilder\Delivery\WebhookRequest;

/**
 * Builds complete emails and webhook calls for tests.
 */
final class MessageFactory
{
    public static function email(): EmailMessage
    {
        return new EmailMessage(
            form: 'contact',
            name: 'Staff',
            to: ['staff@example.com', 'office@example.com'],
            subject: 'New entry',
            html: '<p>Hello</p>',
            text: 'Hello',
            from: 'web@example.com',
            replyTo: 'ann@example.com',
        );
    }

    public static function webhook(): WebhookRequest
    {
        return new WebhookRequest(
            form: 'contact',
            name: 'CRM',
            method: 'POST',
            url: 'https://crm.example.com/hook',
            headers: ['Content-Type: application/json'],
            body: '{"a":1}',
        );
    }
}
