<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Delivery;

/**
 * One notification email, ready to send: every token resolved and every
 * address checked. It names the notification and the form it is for, so a
 * failure can be reported.
 *
 * {@see toArray()} and {@see fromArray()} carry it through a queue as plain
 * data.
 *
 * @api
 */
final readonly class EmailMessage
{
    /**
     * @param non-empty-list<string> $to
     *
     * @mago-expect lint:excessive-parameter-list Immutable value object; the promoted constructor is its public shape and is called with named arguments.
     */
    public function __construct(
        public string $form,
        public string $name,
        public array $to,
        public string $subject,
        public string $html,
        public string $text,
        public ?string $from = null,
        public ?string $replyTo = null,
    ) {}

    /**
     * A message from {@see toArray()}'s data; null when the data is not one.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $payload = new Payload($data);
        $to      = $payload->strings('to');
        $form    = $payload->string('form');
        $name    = $payload->string('name');
        $subject = $payload->string('subject');
        $html    = $payload->string('html');
        $text    = $payload->string('text');
        if ([] === $to || null === $form || null === $name || null === $subject || null === $html || null === $text) {
            return null;
        }

        return new self(
            form: $form,
            name: $name,
            to: $to,
            subject: $subject,
            html: $html,
            text: $text,
            from: $payload->string('from'),
            replyTo: $payload->string('reply_to'),
        );
    }

    /**
     * @return array{form: string, name: string, to: non-empty-list<string>, subject: string, html: string, text: string, from: string|null, reply_to: string|null}
     */
    public function toArray(): array
    {
        return [
            'form'     => $this->form,
            'name'     => $this->name,
            'to'       => $this->to,
            'subject'  => $this->subject,
            'html'     => $this->html,
            'text'     => $this->text,
            'from'     => $this->from,
            'reply_to' => $this->replyTo,
        ];
    }
}
