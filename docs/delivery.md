# Notifications and delivery

Three registrars handle a submission once `FormSubmissionService` has
validated it. Attach them in this order:

```php
$delivery = new ImmediateDelivery($mailTransport, new CurlWebhookTransport(), $logger);

$service->attach(new StoreSubmissionRegistrar($entries));
$service->attach(new EmailNotificationRegistrar($tokens, $delivery, $logger, subjectPrefix: '[Site] '));
$service->attach(new WebhookRegistrar(delivery: $delivery));
```

## Storing

`StoreSubmissionRegistrar` records the entry through an
`EntryRepositoryInterface`: complete, or spam when the honeypot caught it,
with the context's `ip`, `user_id` and `meta`. It writes `entry_id` and
`entry_status` back to the registry, so the registrars after it, and
`{entry:id}`, see the stored entry. A database failure is thrown: a
submission that cannot be stored should fail, not be lost.

## Notification emails

`EmailNotificationRegistrar` builds an `EmailMessage` for each enabled
notification whose conditions hold for the submitted values, and nothing for
spam. Conditions take a field's conditional rule shape
(`{"show_when": {"all": [...]}}`); a notification with none always sends.

- Tokens are resolved in the subject, addresses and body. The body is HTML
  (`TokenReplacer::replaceForHtml()`, so submitted values are escaped), and a
  plain-text version goes with it.
- Recipients are split on spaces, commas and semicolons. An address that is
  not valid once resolved is dropped; a notification left with no recipient
  is logged and not sent. An invalid From or Reply-To is left off.

## Delivery

The registrars hand each email and webhook call to a `DeliveryInterface`.

- `ImmediateDelivery` sends it during the request and logs a failure as a
  warning rather than throwing. Emails go through a `MailTransportInterface`
  the host supplies (the library depends on no mail library: adapt
  laminas-mail, Symfony Mailer or Zend_Mail to it); without one, emails are
  logged as not sent. Webhooks go through a `WebhookTransportInterface`,
  `CurlWebhookTransport` by default.
- To send later, with retries, implement `DeliveryInterface` to push each
  message onto a queue as `toArray()` data. The job rebuilds it with
  `EmailMessage::fromArray()` or `WebhookRequest::fromArray()` and sends it
  through a transport, throwing to retry when `send()` throws or the
  `WebhookResponse` says it `failed()`.

A webhook's body is signed when it is built, so a queued call is sent exactly
as signed.
