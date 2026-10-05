# Merge tags

`TokenReplacer` expands `{namespace:key}` tags in notification subjects,
bodies and redirect URLs.

```php
$replacer = new TokenReplacer(['base_url' => 'https://example.com', 'admin_url' => '…']);
$replacer->register('settings', fn (string $key): ?string => $settings[$key] ?? null);

$replacer->replace('Hi {field:name}, re: {form:title}', $definition, $values, $entry);
$replacer->replaceForUrl('/thanks?name={field:name}', $definition, $values, $entry); // rawurlencode()d
```

| Tag | Resolves to |
| --- | --- |
| `{field:<name>}` | The submitted value; lists are joined with `, ` |
| `{form:title}`, `{form:slug}`, `{form:description}` | Form attributes |
| `{entry:id}`, `{entry:date}`, `{entry:ip}`, `{entry:status}` | Scalar keys of the `$entry` array |
| `{entry:fields}` | An inline-styled HTML table of every field's label and value (escaped) |
| `{site:<key>}` | Keys of the constructor's site context |
| `{<custom>:<key>}` | A registered resolver's result |

- Namespaces are case-insensitive; keys use letters, digits, `_`, `-` and `.`.
- **Unknown tags are left as they are**, so typos stay visible. A custom
  resolver returns `null` to leave its tag, or a string (possibly empty) to
  replace it.
- `{entry:fields}` skips `hidden` fields and `content` blocks. Empty values show
  an em dash, checkboxes show Yes/No and textareas keep line breaks.
- `{field:*}` values are inserted as submitted. Escape the result if the
  template is HTML and values come from the public.
