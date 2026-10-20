<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Registrar;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Definition\WebhookDefinition;
use Contenir\FormBuilder\Delivery\CurlWebhookTransport;
use Contenir\FormBuilder\Delivery\DeliveryInterface;
use Contenir\FormBuilder\Delivery\ImmediateDelivery;
use Contenir\FormBuilder\Delivery\WebhookRequest;
use Contenir\FormBuilder\Service\BuilderForm;
use Override;
use Psr\Log\LoggerInterface;
use SplObserver;
use SplSubject;

use function hash_hmac;
use function is_array;
use function json_encode;
use function preg_match;
use function sprintf;
use function trim;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Fires every enabled webhook configured on a form after a successful
 * submission, POSTing a JSON envelope of the form / entry / values to
 * each target URL.
 *
 * Spam-flagged submissions are skipped — the registrar is for legit
 * leads, not bot traffic.
 *
 * Each call goes through a {@see DeliveryInterface}: by default an
 * {@see ImmediateDelivery} that sends it at once with curl and logs a
 * failure to the optional PSR-3 logger rather than throwing; a host can
 * pass one that queues the call instead. A webhook target that's down (or
 * slow, or misconfigured) must never block the user-facing redirect or roll
 * back the persisted entry.
 *
 * Payload shape:
 *
 * ```json
 * {
 *   "form":   { "id": 5, "slug": "contact", "title": "Contact" },
 *   "entry":  { "id": 42, "submitted_at": "...", "ip": "...", "status": "complete" },
 *   "values": { "name": "Alice", "email": "alice@example.com" }
 * }
 * ```
 *
 * When {@see WebhookDefinition::$secret} is set, the registrar adds an
 * `X-Contenir-Signature: sha256=<hex>` header — the receiver verifies by
 * computing `hash_hmac('sha256', $body, $secret)` against the raw body
 * and comparing.
 *
 * @api
 *
 * @mago-expect lint:cyclomatic-complexity Kept whole for 2.0 (payload building and signing in one observer); splitting it is a proposed follow-up.
 */
final readonly class WebhookRegistrar implements SplObserver
{
    private DeliveryInterface $delivery;

    /**
     * @param int $timeoutSeconds How long the default curl transport waits; ignored when $delivery is given.
     *                            Deprecated: pass a delivery built with a CurlWebhookTransport instead.
     * @param DeliveryInterface|null $delivery Where each call goes; by default sent at once, failures logged to $log.
     */
    public function __construct(
        ?LoggerInterface $log = null,
        int $timeoutSeconds = 10,
        ?DeliveryInterface $delivery = null,
    ) {
        $this->delivery = $delivery ?? new ImmediateDelivery(
            webhooks: new CurlWebhookTransport($timeoutSeconds),
            log: $log,
        );
    }

    /**
     * @mago-expect analysis:mixed-assignment The registry is an untyped bag; values and entry are checked with is_array().
     */
    #[Override]
    public function update(SplSubject $subject): void
    {
        if (! $subject instanceof BuilderForm) {
            return;
        }

        $registry = $subject->registry?->getArrayCopy() ?? [];
        $form     = $registry['form'] ?? null;
        if (! $form instanceof FormDefinition) {
            return;
        }
        if (true === ($registry['spam'] ?? false) || [] === $form->webhooks) {
            return;
        }

        $values = $registry['values'] ?? [];
        $entry  = $registry['entry'] ?? [];

        $payload = [
            'form'   => [
                'id'    => $form->id,
                'slug'  => $form->slug,
                'title' => $form->title,
            ],
            'entry'  => is_array($entry) ? $entry : [],
            'values' => is_array($values) ? $values : [],
        ];
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        foreach ($form->webhooks as $webhook) {
            if (! $webhook->enabled) {
                continue;
            }
            $this->dispatch($webhook, $form, $body);
        }
    }

    private function dispatch(WebhookDefinition $webhook, FormDefinition $form, string $body): void
    {
        $url = trim($webhook->url);
        if ('' === $url || ! preg_match('~^https?://~i', $url)) {
            return;
        }

        $headers = [
            'Content-Type: application/json',
            'User-Agent: Contenir-FormBuilder-Webhook/1.0',
        ];
        foreach ($webhook->headers as $name => $value) {
            $headers[] = sprintf('%s: %s', $name, $value);
        }
        if (null !== $webhook->secret && '' !== $webhook->secret) {
            $headers[] = 'X-Contenir-Signature: sha256=' . hash_hmac('sha256', $body, $webhook->secret);
        }

        $this->delivery->webhook(new WebhookRequest(
            form: $form->slug,
            name: $webhook->name,
            method: '' === $webhook->method ? 'POST' : $webhook->method,
            url: $url,
            headers: $headers,
            body: $body,
        ));
    }
}
