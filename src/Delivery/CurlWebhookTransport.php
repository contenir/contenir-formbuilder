<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Delivery;

use Override;

use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;

use const CURLINFO_HTTP_CODE;
use const CURLOPT_CUSTOMREQUEST;
use const CURLOPT_FOLLOWLOCATION;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_SSL_VERIFYHOST;
use const CURLOPT_SSL_VERIFYPEER;
use const CURLOPT_TIMEOUT;

/**
 * Sends a webhook over HTTP with curl, never following a redirect and always
 * verifying TLS.
 *
 * @api
 */
final readonly class CurlWebhookTransport implements WebhookTransportInterface
{
    public function __construct(
        private int $timeoutSeconds = 10,
    ) {}

    #[Override]
    public function send(WebhookRequest $request): WebhookResponse
    {
        $ch = curl_init($request->url);
        if (false === $ch) {
            // curl_init() only returns false when libcurl cannot allocate a handle.
            return new WebhookResponse(0, 'curl could not be initialised'); // @codeCoverageIgnore
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $request->method,
            CURLOPT_POSTFIELDS     => $request->body,
            CURLOPT_HTTPHEADER     => $request->headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);

        return new WebhookResponse(
            (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
            false === $response ? curl_error($ch) : '',
        );
    }
}
