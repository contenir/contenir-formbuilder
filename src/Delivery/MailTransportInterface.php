<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Delivery;

use Throwable;

/**
 * Sends a notification email: the host's mailer, behind the form builder's
 * own message. The library ships none, so it depends on no mail library;
 * a host adapts the one it has (laminas-mail, Symfony Mailer, Zend_Mail).
 *
 * The message's `html` is the body and `text` its plain-text alternative,
 * to be sent together as multipart/alternative.
 *
 * @api
 */
interface MailTransportInterface
{
    /**
     * @throws Throwable When the message cannot be sent.
     */
    public function send(EmailMessage $message): void;
}
