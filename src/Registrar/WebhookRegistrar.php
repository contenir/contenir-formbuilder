<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Registrar;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Definition\WebhookDefinition;
use Contenir\FormBuilder\Service\BuilderForm;
use Override;
use Psr\Log\LoggerInterface;
use SplObserver;
use SplSubject;

use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function hash_hmac;
use function is_array;
use function json_encode;
use function preg_match;
use function sprintf;
use function trim;

use const CURLINFO_HTTP_CODE;
use const CURLOPT_CUSTOMREQUEST;
use const CURLOPT_FOLLOWLOCATION;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_SSL_VERIFYHOST;
use const CURLOPT_SSL_VERIFYPEER;
use const CURLOPT_TIMEOUT;
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
 * Failures are logged via the optional PSR-3 logger rather than
 * propagated. A webhook target that's down (or slow, or misconfigured)
 * must never block the user-facing redirect or roll back the persisted
 * entry.
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
 * @mago-expect lint:cyclomatic-complexity Kept whole for 2.0 (payload building and cURL dispatch in one observer); splitting it is a proposed follow-up.
 */
class WebhookRegistrar implements SplObserver
{
    public function __construct(
        private ?LoggerInterface $log = null,
        private int $timeoutSeconds = 10,
    ) {}

    /**
     * @mago-expect analysis:mixed-assignment The registry is an untyped bag; values and entry are checked with is_array().
     */
    #[Override]
    public function update(SplSubject $subject): void
    {
        if (! $subject instanceof BuilderForm) {
            return;
        }

        $registry = $this->extractRegistry($subject);
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

        $ch = curl_init($url);
        if (false === $ch) {
            // curl_init() only returns false when libcurl cannot allocate a handle.
            return; // @codeCoverageIgnore
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => '' === $webhook->method ? 'POST' : $webhook->method,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = false === $response ? curl_error($ch) : '';

        if (false === $response || $status >= 400) {
            $this->log?->warning(sprintf(
                'Webhook "%s" for form "%s" failed (status %d): %s',
                $webhook->name,
                $form->slug,
                $status,
                '' === $error ? "HTTP {$status}" : $error,
            ));
        }
    }

    /** @return array<string, mixed> */
    private function extractRegistry(BuilderForm $form): array
    {
        return $form->registry?->getArrayCopy() ?? [];
    }
}
