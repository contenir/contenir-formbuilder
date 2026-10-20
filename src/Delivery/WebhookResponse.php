<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Delivery;

/**
 * What came back from a webhook target: the HTTP status, or zero with the
 * transport's own error when no response arrived.
 *
 * @api
 */
final readonly class WebhookResponse
{
    public function __construct(
        public int $status,
        public string $error = '',
    ) {}

    /**
     * Whether the call failed: no response, or a status of 400 or more.
     */
    public function failed(): bool
    {
        return '' !== $this->error || $this->status >= 400;
    }

    /**
     * Why the call failed, for a log: the transport's error, or the status.
     */
    public function reason(): string
    {
        return '' === $this->error ? "HTTP {$this->status}" : $this->error;
    }
}
