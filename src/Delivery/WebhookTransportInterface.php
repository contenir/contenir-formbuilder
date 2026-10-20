<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Delivery;

/**
 * Carries one webhook call to its target and reports what came back. It
 * never throws: a failure is a {@see WebhookResponse} that says so.
 *
 * @api
 */
interface WebhookTransportInterface
{
    public function send(WebhookRequest $request): WebhookResponse;
}
