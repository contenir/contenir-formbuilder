<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Delivery;

/**
 * Where the registrars hand the emails and webhook calls a submission
 * produces. {@see ImmediateDelivery} sends them at once; a host that wants
 * them sent later, with retries, implements this to push each one onto its
 * queue as `toArray()` data, and its job rebuilds the message with
 * `fromArray()` and sends it through a transport, throwing to retry when
 * the send fails.
 *
 * Neither method may throw: a delivery that cannot be made or queued must
 * never stop a submission being stored or the visitor being answered.
 *
 * @api
 */
interface DeliveryInterface
{
    public function email(EmailMessage $message): void;

    public function webhook(WebhookRequest $request): void;
}
