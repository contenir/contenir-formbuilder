# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
adheres to [Semantic Versioning](https://semver.org/).

## [2.0.0-RC3] - 2026-10-20

One storage layer for forms and entries, over php-db, so the CMS and the site
adapters stop carrying their own copies of the loader, repositories and
registrars. See [docs/storage.md](docs/storage.md) and
[docs/delivery.md](docs/delivery.md).

### Added

- The nine `form_*` tables as a php-db migration in `schema/`
  (`Contenir\FormBuilder\Schema`), with the CMS installer's names, columns
  and keys. Where the tables already exist it changes nothing and is only
  recorded as run. `ConfigProvider` gives its path and namespace, and lists
  it under `contenir.schema_migrations`.
- `Loader\FormLoaderInterface` and `PhpDbFormLoader`: whole form definitions
  in seven queries, and form summaries in one.
- `Repository\FormRepositoryInterface` and `PhpDbFormRepository`: every
  change the form editor makes, with multi-statement changes in one
  transaction.
- `Repository\EntryRepositoryInterface` and `PhpDbEntryRepository`: storing,
  listing, filtering, counting, status changes, deletion, `findExpired()` and
  `redact()`, with times from an injected PSR-20 clock.
- `Persistence\PersistenceException`, thrown for every database failure.
- The delivery seam: `Delivery\DeliveryInterface`, `ImmediateDelivery`,
  `EmailMessage` and `WebhookRequest` (with `toArray()` and `fromArray()` for
  queues), `MailTransportInterface`, `WebhookTransportInterface`,
  `CurlWebhookTransport` and `WebhookResponse`.
- `Registrar\StoreSubmissionRegistrar` and `EmailNotificationRegistrar`.
  Notification conditions are evaluated, and the body's submitted values are
  HTML-escaped.
- `Entry\EntryStatusChanger`: sends on an entry held as spam once it is
  marked complete, once at most.
- `Export\EntryCsvWriter`: streamed CSV in batches, spam left out by
  default, formula-safe cells.
- `Redaction\ExpiredEntryRedactor`, `RedactionReport`, `EntryFilesInterface`,
  `StorageEntryFiles` and `NoFileStorage`: redacts entries past their form's
  retention period, deleting their files first.

### Changed

- Requires `php-db/phpdb` ^0.6 and `peptolab/phpdb-migration` ^0.2. Install
  php-db's MySQL or SQLite driver as well.
- `WebhookRegistrar` sends through a `DeliveryInterface`, given as a new third
  constructor argument; without one it sends at once with curl, as before.
  The payload, headers, signature and log messages are unchanged.

### Deprecated

- `WebhookRegistrar`'s `$timeoutSeconds` argument. Pass a delivery built with
  `new CurlWebhookTransport($seconds)` instead.

## [2.0.0-RC2] - 2026-10-19

Safe file uploads: uploads are validated on the server and stored only for a
valid, non-spam submission. See [UPGRADE-2.0.md](UPGRADE-2.0.md#file-uploads).

### Security

- `FormSubmissionService` stored uploads before validation, so spam,
  submissions that failed validation and fields hidden by a conditional rule
  all left orphaned files in storage. Uploads are now validated with the rest
  of the submission and stored only once it is valid and not spam, and only
  for visible fields.
- `file` fields had no server-side size or type check; only the HTML `accept`
  hint limited them. Every `file` field now has a maximum size (10 MB unless
  set) and optional allowed extensions and MIME types. The MIME type is
  detected from the file's contents with finfo, not taken from the client.
- A `file` field's value now comes only from its upload. A client could post
  a storage path as the field's value and have it accepted as if uploaded.

### Added

- `Validator\FileUpload`, the upload check, configured from a `file` field's
  `max_size` (bytes), `extensions` and `mime_types` options, and
  `ValidatorFactory::fileUpload()`, which builds it.
- `FormSubmissionService` takes an optional fourth argument, a PSR-20
  `ClockInterface` that supplies the submission time (`{entry:date}`). It
  defaults to the system clock.

### Changed

- Requires `psr/clock` and `ext-fileinfo`.
- A spam submission's file fields are `null` (they were the stored path).
- A `file` field's input is a Laminas `FileInput` whose built-in
  `is_uploaded_file()` check is off; `FormSubmissionService` makes that check
  through its replaceable closure.

### Fixed

- A required `file` field always failed with "No file was uploaded", even with
  an upload.

## [2.0.0-RC1] - 2026-10-07

The first 2.0 pre-release. The public API keeps its shape. The major version
marks the move to PHP 8.3+ and the QA toolchain shared by all Contenir 2.x
packages, the package rename, typed class constants, and several security and
behaviour fixes. See [UPGRADE-2.0.md](UPGRADE-2.0.md).

The 2.0.0, 2.1.0, 2.1.1 and 2.2.0 tags published on 2026-10-05 were withdrawn
and are folded into this release.

### Security

- Added `TokenReplacer::replaceForHtml()`, which HTML-escapes every resolved
  merge-tag value (`{entry:fields}` excepted, as it is already escaped). Use it
  for any template rendered as HTML: `replace()` inserts submitted values
  as-is, so an HTML notification body built with it let a visitor inject
  markup. contenir/contenir-formbuilder-laminas-mvc uses it for HTML email
  bodies.
- `FormContentSanitizer` let `javascript:` links through when the scheme
  contained a tab, newline or leading control character (`java&#9;script:`).

0.1.5 backports both fixes for projects that stay on 0.1.

### Changed

- Renamed from `contenir/formbuilder` to `contenir/contenir-formbuilder`. The
  package declares `replace` for the old name; require
  `contenir/contenir-formbuilder` instead.
- The `file` field type's suggested storage package is now
  `contenir/contenir-storage`, the renamed `contenir/storage`, and the
  development requirement is `contenir/contenir-storage` `^2.2`. Namespaces
  are unchanged, so a `StorageManager` from `contenir/storage` still works.
- Requires PHP 8.3, 8.4 or 8.5. PHP 8.1 and 8.2 are no longer supported.
- Every concrete class is `final`. Customise through `FieldTypeInterface` /
  `AbstractFieldType`, the new `FormBuilderInterface`, and the existing
  registration and setter hooks.
- `laminas/laminas-session` is now a dependency. The CSRF element every built
  form carries cannot generate or check its token without it.
- Conflicts with `laminas/laminas-stdlib` below 3.21, which raises
  deprecations on PHP 8.4 and 8.5.
- Class constants are typed (`FormDefinition`, `FormBuilderService`,
  `ValidatorFactory`, `RuleEvaluator`).
- The `url` validator type accepts absolute `http`/`https` URLs. It used to be
  the Hostname validator, which rejected every URL with a scheme.
- `FormMarkup` omits attributes whose value is `false` or not scalar, and
  accepts `['value' => …, 'label' => …]` option specs.
- `FormBuilderService` attaches the `confirm` (Identical) validator in the
  field's validator order instead of after all fields.
- `FormBuilderService` no longer declares its own input for the CSRF element.
  The input it added was dead code: `Csrf` is an input provider, and Laminas
  Form already adds the element's required, token-checked input to the
  filter. Submissions without the issued token are still rejected.
- `FormSubmissionService` excludes hidden conditional fields through the
  validation group only; it no longer mutates their inputs. Observers are
  notified only when the builder returns a `BuilderForm`.
- `FormContentSanitizer` drops code that never changed its output:
  `preserveWhiteSpace` (ignored by libxml's HTML parser), the closing wrapper
  tag (the parser closes it), `strtolower()` on tag and attribute names (the
  parser lower-cases them), the reverse visiting order and an `explode()`
  limit. The allow-list and its output are unchanged.
- `ValidatorDefinition::fromArray()` coerces decoded JSON instead of casting.
- The MIT licence's copyright holder is now Contenir, and the text restores
  the missing "USE OR OTHER" wording.

### Added

- `Service\FormBuilderInterface`, implemented by `FormBuilderService`;
  `FormSubmissionService` depends on the interface.
- `FormSubmissionService` takes an optional third argument, a
  `Closure(string): bool` that decides whether a path is an HTTP upload
  (default `is_uploaded_file()`).
- Continuous integration on PHP 8.3, 8.4 and 8.5 against lowest, locked and
  latest dependencies, with coverage reported to Codecov and Infection
  mutation testing at MSI 100%.
- Separate unit and integration test suites with 100% line and branch
  coverage, and a `docs/` folder covering the public API.

### Fixed

- `FormSubmissionService::submit()` no longer throws a `TypeError` from
  `isValid()` when a field is named with an integer-like string (`"0"`,
  `"1"`, `"10"`, …) and a conditional rule hides another field. Laminas reads
  a validation-group key that matches an element name as a nested fieldset
  group, and the group was a plain list whose indexes could match such names.
  The group now skips those indexes; for other names it is the same list as
  before.
- `WebhookRegistrar` no longer calls `curl_close()`, deprecated in PHP 8.5.
- `{entry:fields}` no longer lists `content` blocks as empty rows.
- A non-numeric textarea `rows` option rendered `rows="0"`; it now uses 5.
- A non-numeric `string_length` `max` became a maximum of 0; it is now ignored.
- A `false` attribute such as `disabled` rendered as present (`disabled=""`).
- Array values in POST data, choices, validator options and Laminas value
  options caused "Array to string conversion" warnings.
- A builder returning a plain Laminas form made observer dispatch fail with a
  `TypeError`.

### Removed

- `squizlabs/php_codesniffer`, `phpcs.xml` and `phpunit.xml`, replaced by Mago
  via contenir-qa-tools and `phpunit.xml.dist`.

## [0.1.4] - 2026-05-10

- The submission registry's `entry` attributes are populated before the first
  observer; custom token resolvers return `null` to leave a tag untouched.

## [0.1.3] - 2026-05-10

- `{entry:fields}` merge tag.

## [0.1.2] - 2026-05-10

- Rendered classes renamed to `formbuilder__*`.

## [0.1.1] - 2026-05-10

- `WebhookRegistrar`: JSON POST with an optional HMAC signature.

## [0.1.0] - 2026-05-10

- Initial form-builder engine.
