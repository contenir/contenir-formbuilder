<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Registrar;

use Contenir\FormBuilder\Conditional\RuleEvaluator;
use Contenir\FormBuilder\Definition\NotificationDefinition;
use Contenir\FormBuilder\Delivery\DeliveryInterface;
use Contenir\FormBuilder\Delivery\EmailMessage;
use Contenir\FormBuilder\Delivery\PlainText;
use Contenir\FormBuilder\Service\TokenReplacer;
use Override;
use Psr\Log\LoggerInterface;
use SplObserver;
use SplSubject;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function filter_var;
use function str_replace;
use function trim;

use const FILTER_VALIDATE_EMAIL;

/**
 * Sends each enabled notification of the form after a submission, through a
 * {@see DeliveryInterface}.
 *
 * Nothing is sent for spam. A notification whose conditions do not hold for
 * the submitted values is skipped: conditions take the same shape as a
 * field's conditional rule (`{"show_when": {"all": [...]}}`, evaluated by
 * {@see RuleEvaluator}), and a notification with none always sends.
 *
 * Tokens are resolved in the subject, the addresses and the body. The body
 * is HTML, so submitted values are escaped in it, and a plain-text version
 * goes with it. Recipients are split on spaces, commas and semicolons; an
 * address that is not valid once resolved is dropped, and a notification
 * left with no recipient is logged and not sent. An invalid From or
 * Reply-To is left off.
 *
 * @api
 */
final readonly class EmailNotificationRegistrar implements SplObserver
{
    public function __construct(
        private TokenReplacer $tokens,
        private DeliveryInterface $delivery,
        private ?LoggerInterface $log = null,
        private string $subjectPrefix = '',
        private RuleEvaluator $conditions = new RuleEvaluator(),
    ) {}

    private static function address(string $address): ?string
    {
        return self::isAddress($address) ? $address : null;
    }

    private static function isAddress(string $address): bool
    {
        return false !== filter_var($address, FILTER_VALIDATE_EMAIL);
    }

    #[Override]
    public function update(SplSubject $subject): void
    {
        $submission = Submission::of($subject);
        if (null === $submission || $submission->spam) {
            return;
        }

        foreach ($submission->form->notifications as $notification) {
            if (
                ! $notification->enabled
                || ! $this->conditions->shouldShow($notification->conditions, $submission->values)
            ) {
                continue;
            }
            $this->send($notification, $submission);
        }
    }

    private function send(NotificationDefinition $notification, Submission $submission): void
    {
        [$form, $values, $entry] = [$submission->form, $submission->values, $submission->entry];
        $resolve = fn(string $template): string => trim($this->tokens->replace($template, $form, $values, $entry));
        $to      = array_values(array_filter(
            array_map($resolve, explode(' ', string: str_replace(
                [',', ';', "\t", "\r", "\n"],
                replace: ' ',
                subject: $notification->toAddress,
            ))),
            self::isAddress(...),
        ));
        if ([] === $to) {
            $this->log?->warning(
                "Form notification \"{$notification->name}\" failed for form \"{$form->slug}\": no valid recipient",
            );

            return;
        }

        $html = $this->tokens->replaceForHtml($notification->bodyTemplate ?? '', $form, $values, $entry);
        $this->delivery->email(new EmailMessage(
            form: $form->slug,
            name: $notification->name,
            to: $to,
            subject: $this->subjectPrefix . $this->tokens->replace($notification->subject, $form, $values, $entry),
            html: $html,
            text: PlainText::fromHtml($html),
            from: self::address($resolve($notification->fromAddress ?? '')),
            replyTo: self::address($resolve($notification->replyTo ?? '')),
        ));
    }
}
