# Building and submitting

## FormBuilderService

```php
$builder = new FormBuilderService(new FieldTypeRegistry(), new ValidatorFactory());
$form    = $builder->build($definition); // a BuilderForm
```

The form posts (`method="post"`), carries the classes
`formbuilder__form formbuilder__form--stacked`, and contains:

- one element and input per data field, with the field's validators and
  filters (unknown types and static `content` fields are skipped);
- `_csrf` (`FormBuilderService::CSRF_NAME`), a Laminas `Csrf` element with the
  salt `contenir_formbuilder`, required;
- `hid` (`FormBuilderService::HONEYPOT_NAME`), the optional honeypot text input;
- `_submit`, labelled with `submitLabel`.

The CSRF token lives in a laminas-session container, so a session must be
available when the form is rendered and validated.

## FormSubmissionService

```php
$service = new FormSubmissionService($builder, $storageManager /* optional */);
$service->attach($observer); // SplObserver, notified in attach order
$result  = $service->submit($definition, $_POST, $_FILES, [
    'ip'      => $_SERVER['REMOTE_ADDR'] ?? null,
    'user_id' => $userId,
    'meta'    => [],
]);
```

`submit()` does the following:

1. Builds the form.
2. Flags spam when the honeypot is non-empty or not a string.
3. Stores `file` uploads (see below) and replaces their POST value with the
   stored path.
4. Leaves fields whose [conditional rule](conditional-logic.md) fails out of
   validation.
5. Validates. A failed, non-spam submission returns immediately, without
   notifying observers.
6. Collects the values (validated data when valid, raw POST for invalid spam)
   minus `_csrf`, `hid`, `_submit` and hidden conditional fields.
7. Notifies the observers.

It throws Laminas form exceptions for a broken form and `StorageException`
when the storage backend fails.

### SubmissionResult

| Property | Meaning |
| --- | --- |
| `valid` | Passed validation and is not spam |
| `form` | The built form, with messages when invalid |
| `values` | Submitted values, empty when invalid |
| `errors` | Laminas messages keyed by element name |
| `isSpam` | The honeypot was filled; `valid` is false. Answer as if it succeeded |
| `entryId` | The integer `entry_id` an observer wrote to the registry, or null |

### Observers and the registry

Observers receive the `BuilderForm` (an `SplSubject`). Its public `registry`
property is an `ArrayObject` with:

| Key | Value |
| --- | --- |
| `form` | The `FormDefinition` |
| `values` | The collected values |
| `spam` | bool |
| `context` | The `$context` passed to `submit()` |
| `entry` | `['id' => ?int, 'date' => 'Y-m-d H:i:s', 'ip' => …, 'status' => …]` |

An observer that stores the submission (by convention attached first) writes
`entry_id` (an int) and optionally `entry_status` to the registry. The `entry`
attributes are refreshed after every observer, so later observers (emails,
webhooks) see the id. The date is captured once per submission.

Observers are only notified when the builder returns a `BuilderForm`.
`BuilderForm` also implements `attach()`, `detach()` and `notify()` for
callers that want to drive observers themselves.

### File uploads

Fields of type `file` are stored when:

- a `StorageManager` was passed and has the `local` (default) profile;
- the matching `$_FILES` entry has `error === UPLOAD_ERR_OK`;
- its `tmp_name` is an HTTP upload (`is_uploaded_file()`).

Files go to `forms/<form slug>/` through `StorageInterface::store()`. Without
a storage manager, uploads are skipped silently. The upload check is the
protected `isUploadedFile(string $path): bool`, which subclasses may override.
