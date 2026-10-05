# Definitions

Every class in `Contenir\FormBuilder\Definition` is an immutable value object
with public readonly properties. Loaders (a database, JSON, plain PHP) build
them; the rest of the package only reads them. Construct them with named
arguments.

## Layout

```
FormDefinition
└── SectionDefinition[]     key, legend, description, sort
    └── GroupDefinition[]   legend, description, sort (rendered as a <fieldset>)
        └── RowDefinition[] sort (up to four columns)
            └── FieldDefinition[]
```

## FormDefinition

| Property | Type | Default |
| --- | --- | --- |
| `id` | `?int` | required |
| `slug` | `string` | required |
| `title` | `string` | required |
| `description` | `?string` | `null` |
| `layoutMode` | `string` | `LAYOUT_SINGLE` |
| `submitLabel` | `string` | `'Submit'` |
| `submitAlignment` | `string` | `'left'` (becomes a `formbuilder__actions--*` modifier) |
| `settings` | `array<string, mixed>` | `[]` (host-specific, e.g. `settings.success.mode`) |
| `retentionDays` | `?int` | `null` |
| `status` | `string` | `STATUS_ACTIVE` |
| `sections` | `list<SectionDefinition>` | `[]` |
| `notifications` | `list<NotificationDefinition>` | `[]` |
| `webhooks` | `list<WebhookDefinition>` | `[]` |

Constants: `LAYOUT_SINGLE`, `LAYOUT_STEPPED`, `STATUS_ACTIVE`,
`STATUS_INACTIVE`, `SUCCESS_REDIRECT_REFERRER`, `SUCCESS_REDIRECT_URL`,
`SUCCESS_INLINE_MESSAGE` and the list `SUCCESS_MODES`. All are typed
(`string` / `array`).

`getAllFields(): list<FieldDefinition>` flattens every field in layout order.

## FieldDefinition

| Property | Type | Default | Meaning |
| --- | --- | --- | --- |
| `id` | `?int` | required | |
| `type` | `string` | required | A key in the [field type registry](field-types.md) |
| `name` | `string` | required | Element and POST name |
| `label` | `?string` | `null` | |
| `showLabel` | `bool` | `true` | |
| `description` | `?string` | `null` | Help text under the input |
| `placeholder` | `?string` | `null` | |
| `defaultValue` | `?string` | `null` | Comma-separated for multi-value types |
| `required` | `bool` | `false` | |
| `colSpan` | `int` | `4` | Column width out of four |
| `sort` | `int` | `0` | |
| `options` | `array<string, mixed>` | `[]` | Type-specific (`choices`, `rows`, `pattern`, `max_length`, `min`, `max`, `step`, `accept`, `html`) |
| `validators` | `list<ValidatorDefinition>` | `[]` | |
| `filters` | `list<string>` | `[]` | Laminas filter names, attached by name |
| `conditional` | `?array` | `null` | A [conditional rule](conditional-logic.md) |

Choice types read `options['choices']` as a list of `['value' => …, 'label' => …]`;
a missing label falls back to the value.

## SectionDefinition, GroupDefinition, RowDefinition

`SectionDefinition(id, key, legend = null, description = null, sort = 0, groups = [])`.
The key identifies the step in stepped forms.

`GroupDefinition(id, legend = null, description = null, sort = 0, rows = [])`.

`RowDefinition(id, sort = 0, fields = [])`. The four-column limit is
enforced by whoever persists rows, not here.

## ValidatorDefinition

`ValidatorDefinition(type, options = [], message = null)`, plus:

- `fromArray(array $data): self` builds one from decoded JSON. A scalar
  `type` or `message` becomes a string, anything else becomes `''` / `null`;
  non-array `options` become `[]`.
- `toArray(): array{type, options, message}`.

See [validators](validators.md) for the types.

## NotificationDefinition

`NotificationDefinition(id, name, trigger = 'submit', toAddress = '', fromAddress = null, replyTo = null, subject = '', bodyTemplate = null, conditions = null, enabled = true, sort = 0)`.
The core only carries it; the laminas-mvc adapter sends the email, expanding
[merge tags](merge-tags.md) in the subject and body.

## WebhookDefinition

`WebhookDefinition(id, name, url, method = 'POST', secret = null, headers = [], enabled = true, sort = 0)`.
See [webhooks](webhooks.md).
