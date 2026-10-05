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
