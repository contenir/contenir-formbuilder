<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Delivery;

use Contenir\FormBuilder\Delivery\EmailMessage;
use Contenir\FormBuilder\Tests\TestAsset\Delivery\MessageFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

#[Group('unit')]
final class EmailMessageTest extends TestCase
{
    /**
     * @return array<string, array{string, mixed}>
     */
    public static function brokenProvider(): array
    {
        return [
            'no recipients'           => ['to', []],
            'recipients not a list'   => ['to', 'a@example.com'],
            'no form'                 => ['form', null],
            'no name'                 => ['name', null],
            'no subject'              => ['subject', null],
            'no html'                 => ['html', null],
            'text that is not a text' => ['text', 5],
        ];
    }

    #[Test]
    public function dropsARecipientThatIsNotAString(): void
    {
        static::assertSame(
            ['a@example.com'],
            EmailMessage::fromArray([...MessageFactory::email()->toArray(), 'to' => [7, 'a@example.com', null]])?->to,
        );
    }

    #[Test]
    public function keepsAMissingFromAndReplyToMissing(): void
    {
        $message = new EmailMessage('contact', 'Staff', ['a@example.com'], 'S', '<p>B</p>', 'B');

        static::assertEquals($message, EmailMessage::fromArray($message->toArray()));
    }

    #[Test]
    #[DataProvider('brokenProvider')]
    public function refusesDataThatIsNotAMessage(string $key, mixed $value): void
    {
        static::assertNull(EmailMessage::fromArray([...MessageFactory::email()->toArray(), $key => $value]));
    }

    #[Test]
    public function survivesAQueueAsJson(): void
    {
        $message = MessageFactory::email();
        $queued  = json_decode((string) json_encode($message->toArray()), associative: true);

        static::assertEquals($message, EmailMessage::fromArray((array) $queued));
    }
}
