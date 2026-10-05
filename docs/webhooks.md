# Webhooks

`WebhookRegistrar` is an `SplObserver` that posts every legitimate submission
to the form's enabled webhooks.

```php
$service->attach(new WebhookRegistrar($logger /* ?LoggerInterface */, timeoutSeconds: 10));
```

For each `WebhookDefinition` that is enabled and has an `http://` or
`https://` URL, it sends:

```json
{
  "form":   { "id": 5, "slug": "contact", "title": "Contact" },
  "entry":  { "id": 42, "date": "2026-10-05 10:00:00", "ip": "…", "status": "complete" },
  "values": { "name": "Alice", "email": "alice@example.com" }
}
```

- **Request:** the definition's method (`POST` when empty),
  `Content-Type: application/json`,
  `User-Agent: Contenir-FormBuilder-Webhook/1.0` and the definition's extra
  headers. Redirects are not followed and TLS is verified.
- **Signature:** with a non-empty `secret`, `X-Contenir-Signature: sha256=<hex>`
  carries `hash_hmac('sha256', $rawBody, $secret)`. Receivers should recompute
  it over the raw body and compare with `hash_equals()`.
- **Skipped:** spam-flagged submissions, subjects that are not a `BuilderForm`
  with a registry holding a `FormDefinition`, and forms without webhooks.
- **Failures** (connection errors and HTTP 400+) are logged as warnings and
  never thrown, so a slow or broken endpoint cannot block the visitor's
  response or roll back the stored entry.
