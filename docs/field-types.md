# Field types

A field type turns a `FieldDefinition` into a Laminas element and tells the
builder UI which settings apply to it.

## Built-in types

| Key | Element | HTML5 hints from `options` |
| --- | --- | --- |
| `text` | `Text` | `pattern` (or the first `regex` validator), `max_length` (or the first `string_length` max) |
| `textarea` | `Textarea` | `rows` (default 5) |
| `email` | `Email` | `autocomplete="email"`, `inputmode="email"` |
| `url` | `Url` | `inputmode="url"` |
| `tel` | `Tel` | `inputmode="tel"`, `pattern` (or the first `regex` validator), `max_length` |
| `number` | `Number` | numeric `min`, `max`, `step`; `inputmode="numeric"` |
| `date` | `Date` | `min`, `max` |
| `datetime` | `DateTimeLocal` | |
| `time` | `Time` | |
| `select` | `Select` | `choices` |
| `multiselect` | `Select` (`multiple`) | `choices`; default is comma-separated |
| `radio` | `Radio` | `choices` |
| `checkbox` | `Checkbox` | |
| `multicheckbox` | `MultiCheckbox` | `choices`; default is comma-separated |
| `file` | `File` | `accept` |
| `hidden` | `Hidden` | |
| `content` | `Hidden` (never added) | Static block: `options['html']` is rendered, sanitized |

Every type also applies the universal attributes: the label (blank when
`showLabel` is false), `required="required"`, `placeholder`, the default value,
and the `formbuilder__control` class.

## FieldTypeInterface

```php
public function key(): string;                 // persisted in form_field.type
public function label(): string;               // shown in the type picker
public function isUserSelectable(): bool;      // false hides it from the picker
public function icon(): string;                // Tabler icon name
public function supportedGroups(): array;      // field-edit UI sections, see below
public function isStatic(): bool;              // true: renders content, collects nothing
public function supportedValidators(): array;  // ValidatorFactory types offered
public function buildElement(FieldDefinition $field): ElementInterface;
```

`supportedGroups()` returns any of `label`, `visibility`, `description`,
`placeholder`, `default`, `required`, `validation`, `options`, `choices`,
`conditional`.

## AbstractFieldType

Extend it to write a type. Implement `key()`, `label()` and
`createElement(FieldDefinition $field): ElementInterface`. Override as needed:

- `applyHtml5Hints(ElementInterface $element, FieldDefinition $field): void`
- `valueForDefault(FieldDefinition $field): mixed`
- `icon()`, `isUserSelectable()`, `isStatic()`, `supportedGroups()`,
  `supportedValidators()` (defaults: `cursor-text`, `true`, `false`, the
  text-like groups, `['string_length', 'regex', 'confirm']`)

```php
final class RatingField extends AbstractFieldType
{
    public function key(): string { return 'rating'; }

    public function label(): string { return 'Star rating'; }

    protected function createElement(FieldDefinition $field): ElementInterface
    {
        $element = new Number($field->name);
        $element->setAttributes(['min' => '1', 'max' => '5']);

        return $element;
    }
}
```

## FieldTypeRegistry

```php
$registry = new FieldTypeRegistry([new RatingField()]); // built-ins plus extras
$registry->register(new RatingField());                 // or later; same key replaces
$registry->has('rating');                               // bool
$registry->get('rating');                               // throws OutOfBoundsException when unknown
$registry->all();                                       // list<FieldTypeInterface>
$registry->userSelectable();                            // only isUserSelectable() types
FieldTypeRegistry::defaultTypes();                      // fresh instances of the built-ins
```

`FormBuilderService` skips fields whose type is not registered.
