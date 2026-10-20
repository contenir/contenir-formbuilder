<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Registrar;

use ArrayObject;
use Contenir\FormBuilder\Definition\NotificationDefinition;
use Contenir\FormBuilder\Delivery\EmailMessage;
use Contenir\FormBuilder\Registrar\EmailNotificationRegistrar;
use Contenir\FormBuilder\Service\BuilderForm;
use Contenir\FormBuilder\Service\TokenReplacer;
use Contenir\FormBuilder\Tests\TestAsset\Delivery\RecordingDelivery;
use Contenir\FormBuilder\Tests\TestAsset\Factory\FormDefinitionFactory as F;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SplSubject;

use function array_map;

#[Group('unit')]
final class EmailNotificationRegistrarTest extends TestCase
{
    private RecordingDelivery $delivery;

    /**
     * @return array<string, array{SplSubject}>
     */
    public static function nothingToSendProvider(): array
    {
        $notification = [new NotificationDefinition(
            id: 1,
            name: 'Staff',
            toAddress: 'a@example.com',
        )];

        return [
            'no registry'        => [new BuilderForm()],
            'no form definition' => [self::submission($notification, ['form' => 'contact'])],
            'spam'               => [self::submission($notification, ['spam' => true])],
        ];
    }

    /**
     * @param list<NotificationDefinition> $notifications
     * @param array<string, mixed> $registry
     */
    private static function submission(array $notifications, array $registry = []): BuilderForm
    {
        $form           = new BuilderForm();
        $form->registry = new ArrayObject([
            'form'   => F::form([F::field('text', 'name'), F::field('email', 'email')], notifications: $notifications),
            'values' => ['name' => 'Ann <b>&</b>', 'email' => 'ann@example.com', 'topic' => 'sales'],
            'entry'  => ['id' => 42],
            'spam'   => false,
            ...$registry,
        ]);

        return $form;
    }

    #[Test]
    public function leavesOffAFromOrReplyToThatIsNotAnAddress(): void
    {
        $this->registrar()->update(self::submission([new NotificationDefinition(
            id: 1,
            name: 'Staff',
            toAddress: 'staff@example.com',
            fromAddress: '{field:name}',
            subject: 'S',
        )]));

        static::assertSame(
            [null, null, '', ''],
            [
                $this->delivery->emails[0]->from,
                $this->delivery->emails[0]->replyTo,
                $this->delivery->emails[0]->html,
                $this->delivery->emails[0]->text,
            ],
        );
    }

    #[Test]
    public function logsANotificationLeftWithNoRecipient(): void
    {
        $log = $this->createMock(LoggerInterface::class);
        $log->expects($this->once())
            ->method('warning')
            ->with('Form notification "Staff" failed for form "contact": no valid recipient');

        $this->registrar($log)->update(self::submission([
            new NotificationDefinition(
                id: 1,
                name: 'Staff',
                toAddress: ' {field:name} , ; ',
            ),
        ]));

        static::assertSame([], $this->delivery->emails);
    }

    #[Test]
    public function readsValuesAndEntryOfTheWrongTypeAsEmpty(): void
    {
        $this->registrar()->update(self::submission(
            [new NotificationDefinition(
                id: 1,
                name: 'Staff',
                toAddress: 'a@example.com',
                subject: '{field:name}{entry:id}',
            )],
            ['values' => 'x', 'entry' => 'y'],
        ));

        static::assertSame('{field:name}{entry:id}', $this->delivery->emails[0]->subject);
    }

    #[Test]
    public function sendsANotificationWithItsTokensResolved(): void
    {
        $this->registrar(subjectPrefix: '[Site] ')->update(self::submission([new NotificationDefinition(
            id: 1,
            name: 'Staff',
            toAddress: "staff@example.com, {field:email};\nnot-an-address\toffice@example.com",
            fromAddress: ' {site:from} ',
            replyTo: '{field:email}',
            subject: 'Entry {entry:id} from {field:name}',
            bodyTemplate: '<p>From {field:name}</p><p>Entry {entry:id}</p>',
        )]));

        static::assertEquals(
            [new EmailMessage(
                form: 'contact',
                name: 'Staff',
                to: ['staff@example.com', 'ann@example.com', 'office@example.com'],
                subject: '[Site] Entry 42 from Ann <b>&</b>',
                html: '<p>From Ann &lt;b&gt;&amp;&lt;/b&gt;</p><p>Entry 42</p>',
                text: "From Ann <b>&</b>\nEntry 42",
                from: 'web@example.com',
                replyTo: 'ann@example.com',
            )],
            $this->delivery->emails,
        );
    }

    #[Test]
    #[DataProvider('nothingToSendProvider')]
    public function sendsNothingWithoutALegitimateSubmission(SplSubject $subject): void
    {
        $this->registrar()->update($subject);

        static::assertSame([], $this->delivery->emails);
    }

    #[Test]
    public function sendsOnlyEnabledNotificationsWhoseConditionsHold(): void
    {
        $when = static fn(string $topic): array => [
            'show_when' => [
                'all' => [
                    ['field' => 'topic', 'op' => 'equals', 'value' => $topic],
                ],
            ],
        ];

        $this->registrar()->update(self::submission([
            new NotificationDefinition(
                id: 1,
                name: 'Off',
                toAddress: 'a@example.com',
                enabled: false,
            ),
            new NotificationDefinition(
                id: 2,
                name: 'Sales',
                toAddress: 'a@example.com',
                conditions: $when('sales'),
            ),
            new NotificationDefinition(
                id: 3,
                name: 'Support',
                toAddress: 'a@example.com',
                conditions: $when('support'),
            ),
            new NotificationDefinition(
                id: 4,
                name: 'Always',
                toAddress: 'a@example.com',
            ),
        ]));

        static::assertSame(
            ['Sales', 'Always'],
            array_map(static fn(EmailMessage $message): string => $message->name, $this->delivery->emails),
        );
    }

    protected function setUp(): void
    {
        $this->delivery = new RecordingDelivery();
    }

    private function registrar(?LoggerInterface $log = null, string $subjectPrefix = ''): EmailNotificationRegistrar
    {
        $tokens = new TokenReplacer();
        $tokens->register('site', static fn(string $key): ?string => 'from' === $key ? 'web@example.com' : null);

        return new EmailNotificationRegistrar($tokens, $this->delivery, $log, $subjectPrefix);
    }
}
