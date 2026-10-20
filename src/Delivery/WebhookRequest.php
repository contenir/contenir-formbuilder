<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Delivery;

use SensitiveParameter;

/**
 * One webhook call, ready to send: the body already signed, its signature
 * among the headers. It names the webhook and the form it is for, so a
 * failure can be reported.
 *
 * {@see toArray()} and {@see fromArray()} carry it through a queue as plain
 * data.
 *
 * @api
 */
final readonly class WebhookRequest
{
    /**
     * @param list<string> $headers `Name: value` lines
     *
     * @mago-expect lint:excessive-parameter-list Immutable value object; the promoted constructor is its public shape.
     */
    public function __construct(
        public string $form,
        public string $name,
        public string $method,
        public string $url,
        #[SensitiveParameter]
        public array $headers,
        public string $body,
    ) {}

    /**
     * A request from {@see toArray()}'s data; null when the data is not one.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $payload = new Payload($data);
        $form    = $payload->string('form');
        $name    = $payload->string('name');
        $method  = $payload->string('method');
        $url     = $payload->string('url');
        $body    = $payload->string('body');
        if (null === $form || null === $name || null === $method || null === $url || null === $body) {
            return null;
        }

        return new self($form, $name, $method, $url, $payload->strings('headers'), $body);
    }

    /**
     * @return array{form: string, name: string, method: string, url: string, headers: list<string>, body: string}
     */
    public function toArray(): array
    {
        return [
            'form'    => $this->form,
            'name'    => $this->name,
            'method'  => $this->method,
            'url'     => $this->url,
            'headers' => $this->headers,
            'body'    => $this->body,
        ];
    }
}
