# Validators

`ValidatorFactory` maps the curated validator vocabulary onto Laminas
validators. Field definitions carry `ValidatorDefinition`s; the builder asks
the factory for each one.

| Type (constant) | Result | Options |
| --- | --- | --- |
| `required` (`TYPE_REQUIRED`) | `null`; the builder makes the input required | |
| `string_length` (`TYPE_STRING_LENGTH`) | `StringLength` | numeric `min` (default 0), numeric `max` (default none) |
| `between` (`TYPE_BETWEEN`) | `Between` | `min` (0), `max` (`PHP_INT_MAX`), `inclusive` (true; `'0'`, `'false'`, `''` mean false) |
| `email` (`TYPE_EMAIL`) | `EmailAddress` | |
| `url` (`TYPE_URL`) | `Callback` accepting absolute `http`/`https` URLs | |
| `regex` (`TYPE_REGEX`) | `Regex` | `pattern` (default `/.*/`) |
| `confirm` (`TYPE_CONFIRM`) | `null`; the builder attaches `Identical` against `options['field']` | `field` |

A non-empty `message` on the definition replaces every message template of the
validator. An unknown type throws `InvalidArgumentException`.

```php
$factory   = new ValidatorFactory();
$validator = $factory->create(new ValidatorDefinition('string_length', ['max' => 80], 'Keep it short'));
```

`ValidatorFactory::vocabulary()` lists the types the field editor offers
(everything except `required`, which has its own checkbox), each as
`['type' => …, 'label' => …, 'requires_options' => bool]`.

## File uploads

Every `file` field gets a `FileUpload` check, built by
`ValidatorFactory::fileUpload()` from the field's options. It is not part of
the vocabulary: the builder attaches it whether or not the field lists any
validators.

| Option | Meaning | Default |
| --- | --- | --- |
| `max_size` | Largest file in bytes; a whole number above 0 | 10 MB (`FileUpload::DEFAULT_MAX_SIZE`, 10,485,760 bytes) |
| `extensions` | Allowed extensions of the client file name, as a list or comma-separated string; a leading dot and case are ignored | any |
| `mime_types` | Allowed MIME types, as a list or comma-separated string; case is ignored | any |

```php
new FieldDefinition(null, 'file', 'cv', options: [
    'accept'     => '.pdf,.docx', // browser hint only
    'max_size'   => 5 * 1024 * 1024,
    'extensions' => 'pdf, docx',
    'mime_types' => ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
]);
```

The size is read from the uploaded file and the MIME type is detected from its
contents with finfo; the client's `size` and `type` are not trusted. Each
failed check adds its own message, keyed by a `FileUpload` constant:

| Key | Message |
| --- | --- |
| `FileUpload::TOO_LARGE` | The file is larger than the 10 MB limit. |
| `FileUpload::EXTENSION` | Only these file types can be uploaded: .pdf, .docx. |
| `FileUpload::MIME_TYPE` | The file's contents are not an allowed type (application/pdf). |
| `FileUpload::INVALID` | The file could not be read. Please upload it again. |

`accept` stays a hint for the browser's file picker; it is not checked on the
server.
