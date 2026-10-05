# contenir/formbuilder

[![Continuous Integration](https://github.com/contenir/formbuilder/actions/workflows/continuous-integration.yml/badge.svg)](https://github.com/contenir/formbuilder/actions/workflows/continuous-integration.yml)
[![codecov](https://codecov.io/gh/contenir/formbuilder/graph/badge.svg)](https://codecov.io/gh/contenir/formbuilder)

Framework-agnostic form-builder engine for [Contenir CMS](https://github.com/contenir).

It turns a runtime-editable form definition (sections, groups, rows and
fields, usually stored in a database) into a working `Laminas\Form\Form`,
validates submissions against it, and renders the markup:

- **Definitions.** Immutable value objects describing a form, its layout,
  notifications and webhooks.
- **Field types.** 17 built-in types behind a registry you can extend.
- **Validators.** A small curated vocabulary mapped onto Laminas validators.
- **Conditional logic.** Show/hide rules evaluated on the server (and
  mirrored by the client).
- **Submission.** CSRF, a honeypot spam trap, conditional gating, optional
  file uploads through [`contenir/storage`](https://github.com/contenir/storage),
  and observers (registrars) that persist or forward the result.
- **Rendering.** Framework-free HTML for single-page or stepped forms.
- **Merge tags.** `{field:name}`, `{form:title}`, `{entry:fields}` and custom
  namespaces for notification templates and redirect URLs.

This is the pure-PHP core. It has no opinion about how definitions are loaded
or how submissions are stored. [`contenir/formbuilder-laminas-mvc`](https://github.com/contenir/formbuilder-laminas-mvc)
wires it into a laminas-mvc application.

## Requirements

- PHP 8.3, 8.4 or 8.5
- laminas-form 3.20+, laminas-inputfilter, laminas-validator, laminas-filter
- laminas-session (backs the CSRF token)
- `ext-curl` for webhooks
- Optional: `contenir/storage` for the `file` field type

## Installation

```bash
composer require contenir/formbuilder
```

The 0.x releases, which support PHP 8.1, remain available from the `0.x`
branch and `v0.*` tags; see [UPGRADE-2.0.md](UPGRADE-2.0.md).

## Usage

```php
use Contenir\FormBuilder\Definition\FieldDefinition;
use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Definition\GroupDefinition;
use Contenir\FormBuilder\Definition\RowDefinition;
use Contenir\FormBuilder\Definition\SectionDefinition;
use Contenir\FormBuilder\FieldType\FieldTypeRegistry;
use Contenir\FormBuilder\Registrar\WebhookRegistrar;
use Contenir\FormBuilder\Render\FormMarkup;
use Contenir\FormBuilder\Service\FormBuilderService;
use Contenir\FormBuilder\Service\FormSubmissionService;
use Contenir\FormBuilder\Validator\ValidatorFactory;

$definition = new FormDefinition(
    id: 1,
    slug: 'contact',
    title: 'Contact us',
    submitLabel: 'Send',
    sections: [new SectionDefinition(id: 1, key: 'main', groups: [
        new GroupDefinition(id: 1, rows: [new RowDefinition(id: 1, fields: [
            new FieldDefinition(id: 1, type: 'text', name: 'name', label: 'Name', required: true),
            new FieldDefinition(id: 2, type: 'email', name: 'email', label: 'Email', required: true),
        ])]),
    ])],
);

$builder = new FormBuilderService(new FieldTypeRegistry(), new ValidatorFactory());

// GET: render the form
$form = $builder->build($definition);
echo (new FormMarkup())->render($definition, $form);

// POST: validate and dispatch
$service = new FormSubmissionService($builder);
$service->attach(new WebhookRegistrar($logger));
$result = $service->submit($definition, $_POST, $_FILES, ['ip' => $_SERVER['REMOTE_ADDR'] ?? null]);

if ($result->valid) {
    // $result->values, $result->entryId
} elseif ($result->isSpam) {
    // answer as if it succeeded
} else {
    echo (new FormMarkup())->render($definition, $result->form); // with errors
}
```

The full public API is described in [docs/](docs/):

| Page | Covers |
| --- | --- |
| [Definitions](docs/definitions.md) | `FormDefinition`, sections, groups, rows, fields, validators, notifications, webhooks |
| [Field types](docs/field-types.md) | The built-in types, `FieldTypeRegistry`, writing your own type |
| [Validators](docs/validators.md) | `ValidatorFactory` vocabulary and options |
| [Conditional logic](docs/conditional-logic.md) | `RuleEvaluator`, `ConditionalRulesParser` |
| [Building and submitting](docs/building-and-submitting.md) | `FormBuilderService`, `FormSubmissionService`, `SubmissionResult`, `BuilderForm`, observers, uploads |
| [Rendering](docs/rendering.md) | `FormMarkup`, `FormContentSanitizer` |
| [Merge tags](docs/merge-tags.md) | `TokenReplacer` |
| [Webhooks](docs/webhooks.md) | `WebhookRegistrar` |

## Development

The QA toolchain is [php-db/phpdb-qa-tools](https://github.com/php-db/phpdb-qa-tools).
[Mago](https://mago.carthage.software/) is a standalone binary, installed
separately (`brew install mago`).

```bash
composer check             # everything below
composer cs-check          # mago format --check && mago lint
composer static-analysis   # mago analyze
composer test              # unit suite: no I/O, no session, no network
composer test-integration  # integration suite: in-memory session, temp files, a local HTTP server
composer test-coverage     # both suites, clover.xml for Codecov
```

The webhook integration tests start PHP's built-in web server on a free
loopback port.

## License

MIT. See [LICENSE](LICENSE).
