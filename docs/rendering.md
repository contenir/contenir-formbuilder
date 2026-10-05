# Rendering

## FormMarkup

```php
$markup = new FormMarkup();
$markup->setEscaper(fn (string $s): string => $escapeHtml($s)); // optional
echo $markup->render($definition, $form);                          // public form
echo $markup->render($definition, $form, preview: true);           // admin preview classes
```

`render()` walks the definition and emits each element the form contains:

- **Single layout:** `<form>`, then each section (wrapped in a
  `<section class="formbuilder__section">` only when it has a legend or
  description), each group as a `<fieldset class="formbuilder__panel">`, each
  row as `formbuilder__row` and each field as `formbuilder__field`, then the
  actions (`_csrf`, the honeypot inside `formbuilder__honeypot`, `_submit`).
- **Stepped layout** (`FormDefinition::LAYOUT_STEPPED` with sections): a step
  list, one `formbuilder__step` section per definition section (the first is
  active, the rest `hidden`), Previous/Next buttons, and the actions on the
  last step. The form gets `formbuilder__form--stepped` and
  `data-form-stepper="true"`.
- **Preview:** structural wrappers use `form-preview__*` classes instead.

Per field it renders the label (required fields get
`formbuilder__label--required`; checkboxes draw their own label after the
input), the control inside `formbuilder__element`, the element's error
messages as `formbuilder__errors`, and the description. Conditional fields get
`data-form-conditional` and start `hidden` when their rule fails. Groups
without rows show "No fields in this group yet.". Fields missing from the
form are skipped. Forms with a file input get
`enctype="multipart/form-data"`.

Attribute values are escaped; attributes that are null, false, empty or not
scalar are omitted. Select and radio options accept Laminas' value-to-label
maps and `['value' => …, 'label' => …]` specs.

Without `setEscaper()`, `htmlspecialchars(ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`
is used.

## FormContentSanitizer

`content` fields render `options['html']` through
`FormContentSanitizer::sanitize(string $html): string`:

- **Kept:** `p`, `h2`–`h4`, `ul`, `ol`, `li`, `blockquote`, `a`, `strong`,
  `em`, `b`, `i`, `br`, `code`, `span`, `small`, `sub`, `sup`.
- **Removed with their content:** `script`, `style`, `iframe`, `form`.
- **Unwrapped (content kept):** every other tag.
- **Attributes:** `class` anywhere; `href`, `title`, `rel`, `target` on `a`.
- **Links:** relative URLs and `http`, `https`, `mailto`, `tel`. Control
  characters and whitespace are ignored when the scheme is checked, as
  browsers do, so `java&#9;script:` is removed.

Hosts should also sanitize the HTML when it is saved.
