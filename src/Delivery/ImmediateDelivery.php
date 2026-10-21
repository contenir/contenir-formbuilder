<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Delivery;

use Override;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends each email and webhook call as soon as it is handed over, during the
 * request, and logs a failure as a warning rather than throwing.
 *
 * Without a mail transport, emails are not sent, and each is logged as not
 * sent.
 *
 * @api
 */
final readonly class ImmediateDelivery implements DeliveryInterface
{
    public function __construct(
        private ?MailTransportInterface $mail = null,
        private WebhookTransportInterface $webhooks = new CurlWebhookTransport(),
        private ?LoggerInterface $log = null,
    ) {}

    #[Override]
    public function email(EmailMessage $message): void
    {
        if (null === $this->mail) {
            $this->emailFailed($message, 'no mail transport is configured');

            return;
        }

        try {
            $this->mail->send($message);
        } catch (Throwable $e) {
            $this->emailFailed($message, $e->getMessage());

            return;
        }

        $this->log?->info("Sent form notification \"{$message->name}\" for form \"{$message->form}\"");
    }

    #[Override]
    public function webhook(WebhookRequest $request): void
    {
        $response = $this->webhooks->send($request);
        if (! $response->failed()) {
            $this->log?->info(
                "Sent webhook \"{$request->name}\" for form \"{$request->form}\" (status {$response->status})",
            );

            return;
        }

        $this->log?->warning(
            "Webhook \"{$request->name}\" for form \"{$request->form}\" failed "
                . "(status {$response->status}): {$response->reason()}",
        );
    }

    private function emailFailed(EmailMessage $message, string $reason): void
    {
        $this->log?->warning("Form notification \"{$message->name}\" failed for form \"{$message->form}\": {$reason}");
    }
}
