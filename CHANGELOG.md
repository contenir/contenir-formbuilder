# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
adheres to [Semantic Versioning](https://semver.org/).

## [2.1.0] - Unreleased

### Security

- Added `TokenReplacer::replaceForHtml()`, which HTML-escapes every resolved
  merge-tag value (`{entry:fields}` excepted, as it is already escaped). Use it
  for any template rendered as HTML: `replace()` inserts submitted values
  as-is, so an HTML notification body built with it let a visitor inject
  markup. contenir/formbuilder-laminas-mvc 2.1 uses it for HTML email bodies.

## [2.0.0] - 2026-10-05

The public API keeps its shape. The major version marks the move to PHP 8.3+
and the php-db QA toolchain shared by all Contenir 2.x packages, typed class
constants, and several behaviour fixes. See [UPGRADE-2.0.md](UPGRADE-2.0.md).

### Changed

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
- `FormSubmissionService` excludes hidden conditional fields through the
  validation group only; it no longer mutates their inputs. Observers are
  notified only when the builder returns a `BuilderForm`.
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
  latest dependencies, with coverage reported to Codecov.
- Separate unit and integration test suites with 100% line and branch
  coverage, and a `docs/` folder covering the public API.

### Fixed

- `FormContentSanitizer` let `javascript:` links through when the scheme
  contained a tab, newline or leading control character (`java&#9;script:`).
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
  via `php-db/phpdb-qa-tools` and `phpunit.xml.dist`.

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
