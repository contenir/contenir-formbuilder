# Upgrading from 0.x to 2.0

2.0 keeps the 0.1 API: the same classes, constructors and methods. Most
applications only update the constraint. Read the list below if you extend the
package's classes or rely on the behaviours that were fixed.

| | 0.x | 2.0 |
| --- | --- | --- |
| PHP | ^8.1 | 8.3, 8.4 or 8.5 |
| laminas-session | not required (needed at runtime anyway) | ^2.16, required |
| laminas-stdlib (indirect) | any | 3.21+ (conflict with older) |
| contenir/storage (optional) | ^0.1 | ^0.1 or ^2.0 |

```bash
composer require contenir/formbuilder:^2.0
```

Projects that must stay on PHP 8.1 or 8.2 can keep using `^0.1`, which is
maintained on the `0.x` branch.

## Typed class constants

Public constants now declare their type. A subclass that redeclares one must
use the same type.

```php
// 0.x
public const LAYOUT_SINGLE = 'single';

// 2.0
public const string LAYOUT_SINGLE = 'single';
```

Affected: `FormDefinition::LAYOUT_*`, `STATUS_*`, `SUCCESS_*` (`string`) and
`SUCCESS_MODES` (`array`); `FormBuilderService::CSRF_NAME`, `HONEYPOT_NAME`;
`ValidatorFactory::TYPE_*`; `RuleEvaluator::OP_*`.

## `url` validator

```php
$validator = (new ValidatorFactory())->create(new ValidatorDefinition('url'));

// 0.x: Laminas\Validator\Hostname. 'example.com' passed, 'https://example.com' failed.
// 2.0: Laminas\Validator\Callback. 'https://example.com' passes, 'example.com' fails.
```

Code that checked `instanceof Hostname` must change. Forms whose `url`
validator was meant for bare host names should use a `regex` validator.

## Rendering

- Attributes set to `false` are now omitted. `setAttribute('disabled', false)`
  rendered `disabled=""` (disabling the input); it now renders nothing.
- Non-scalar attribute values (arrays, objects) are omitted instead of
  rendering `Array` with a warning.
- Select, radio and checkbox-list options given as
  `['value' => 'x', 'label' => 'X']` render as option `x`/`X`. Option groups are
  skipped.
- A textarea whose `rows` option is not numeric renders `rows="5"` (was `0`).

## Content sanitizer

`FormContentSanitizer` removes `href`s whose scheme is only revealed once tabs,
newlines and control characters are ignored:

```php
FormContentSanitizer::sanitize('<a href="java&#9;script:alert(1)">x</a>');
// 0.x: <a href="java	script:alert(1)">x</a>
// 2.0: <a>x</a>
```

## Building and submitting

- The `confirm` validator (Identical) is attached where it appears in the
  field's validator list, not after every other validator. Only the order of
  error messages changes.
- `FieldDefinition::$validators` must contain only `ValidatorDefinition`s; 0.x
  silently skipped anything else, 2.0 fails with an error.
- Hidden conditional fields are excluded through the validation group only;
  their inputs keep their `required` flag. Code reading the input filter after
  `submit()` sees the original configuration.
- Observers are notified only when `FormBuilderService::build()` returns a
  `BuilderForm` (a subclassed builder returning another form used to fail with
  a `TypeError`).
- `FormSubmissionService` has a new protected method,
  `isUploadedFile(string $path): bool`. A subclass that declares a method with
  that name must make it compatible.

## Merge tags and definitions

- `{entry:fields}` no longer includes `content` blocks. A field value of
  `false` renders an em dash instead of an empty cell (or "No" for checkboxes).
- `ValidatorDefinition::fromArray()` coerces decoded JSON: a non-scalar `type`
  becomes `''`, a non-scalar `message` becomes `null`, and non-array `options`
  become `[]`. 0.x cast them, producing `"Array"` with a warning.
- `ValidatorFactory`: a non-numeric `string_length` `max` is ignored (0.x set
  a maximum of 0), and `between`'s `inclusive` option is read as a boolean
  string, so `'false'` now means false.
- `WebhookRegistrar` skips a submission only when the registry's `spam` value
  is `true`; 0.x treated any truthy value as spam.
