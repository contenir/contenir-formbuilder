<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Service;

use Contenir\FormBuilder\Definition\FieldDefinition;
use Contenir\FormBuilder\Definition\FormDefinition;

/**
 * Substitutes `{namespace:key}` merge tags inside notification templates.
 *
 * Built-in namespaces:
 *  - `field:<name>`  → submitted value for the named field
 *  - `form:<attr>`   → form-level attribute (title, slug, description)
 *  - `entry:<attr>`  → entry attribute (id, date, ip, status), plus
 *                      `entry:fields` which expands to an inline-styled
 *                      HTML table of every visible field's label and value
 *  - `site:<attr>`   → site-level attribute (admin_url, base_url)
 *
 * Unknown tokens are left intact so they remain visible to the recipient
 * rather than silently disappearing — this surfaces typos in templates.
 */
class TokenReplacer
{
    /** @var array<string, callable(string): ?string> */
    private array $providers = [];

    /**
     * @param array<string, mixed> $siteContext  Static values for `site:*` tokens.
     */
    public function __construct(array $siteContext = [])
    {
        if ($siteContext !== []) {
            $this->register('site', static function (string $key) use ($siteContext): ?string {
                if (! array_key_exists($key, $siteContext)) {
                    return null;
                }
                $value = $siteContext[$key];
                return is_scalar($value) ? (string) $value : '';
            });
        }
    }

    /**
     * Register an additional namespace handler.
     *
     * The resolver receives the token key (everything after the colon)
     * and returns either a string to substitute (empty string is fine —
     * means "explicitly empty") or null to leave the token untouched in
     * the template (signals the key wasn't recognised, so typos remain
     * visible to the editor).
     *
     * @param callable(string): ?string $resolver
     */
    public function register(string $namespace, callable $resolver): void
    {
        $this->providers[$namespace] = $resolver;
    }

    /**
     * @param array<string, mixed> $values   field name => submitted value
     * @param array<string, mixed> $entry    entry attributes (id, date, ip, status)
     */
    public function replace(string $template, FormDefinition $form, array $values, array $entry = []): string
    {
        return $this->dispatch($template, $form, $values, $entry, null);
    }

    /**
     * Same as {@see replace()} but URL-encodes resolved values via
     * `rawurlencode`. Use when a template is being expanded into a URL
     * (e.g. a custom redirect URL) so submitted field values can't
     * smuggle query separators or path traversal sequences.
     *
     * Tokens that fall through (unknown namespace/key) are left intact
     * and not encoded — encoding `{field:foo}` would corrupt the URL
     * for legitimate authors who haven't yet renamed a field.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $entry
     */
    public function replaceForUrl(string $template, FormDefinition $form, array $values, array $entry = []): string
    {
        return $this->dispatch(
            $template,
            $form,
            $values,
            $entry,
            static fn (string $value): string => rawurlencode($value),
        );
    }

    /**
     * Same as {@see replace()} but HTML-escapes every resolved value, for
     * expanding a template that is rendered as HTML (e.g. an HTML email
     * body), so submitted values can't inject markup.
     *
     * `{entry:fields}` is substituted unescaped: it is a table this class
     * renders itself, with every label and value already escaped. Tokens
     * that fall through are left intact.
     *
     * @param array<string, mixed> $values
     * @param array<string, mixed> $entry
     */
    public function replaceForHtml(string $template, FormDefinition $form, array $values, array $entry = []): string
    {
        return $this->dispatch(
            $template,
            $form,
            $values,
            $entry,
            static fn (string $value, string $tag): string => strtolower($tag) === '{entry:fields}'
                ? $value
                : htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $entry
     * @param (callable(string, string): string)|null $postProcess Receives the resolved value and the matched token.
     */
    private function dispatch(
        string $template,
        FormDefinition $form,
        array $values,
        array $entry,
        ?callable $postProcess,
    ): string {
        if ($template === '') {
            return '';
        }

        return (string) preg_replace_callback(
            '/\{(?<ns>[a-z]+):(?<key>[a-z0-9_\-\.]+)\}/i',
            function (array $matches) use ($form, $values, $entry, $postProcess): string {
                $namespace = strtolower((string) $matches['ns']);
                $key       = (string) $matches['key'];
                $original  = $matches[0];

                $resolved = match ($namespace) {
                    'field' => $this->resolveField($values, $key, $original),
                    'form'  => $this->resolveForm($form, $key, $original),
                    'entry' => $this->resolveEntry($entry, $key, $original, $form, $values),
                    default => $this->resolveCustom($namespace, $key, $original),
                };

                if ($postProcess === null || $resolved === $original) {
                    return $resolved;
                }

                return $postProcess($resolved, $original);
            },
            $template
        );
    }

    /** @param array<string, mixed> $values */
    private function resolveField(array $values, string $key, string $original): string
    {
        if (! array_key_exists($key, $values)) {
            return $original;
        }
        $value = $values[$key];
        if (is_array($value)) {
            $flat = array_filter($value, static fn ($v): bool => is_scalar($v));
            return implode(', ', array_map(static fn ($v): string => (string) $v, $flat));
        }
        return is_scalar($value) ? (string) $value : '';
    }

    private function resolveForm(FormDefinition $form, string $key, string $original): string
    {
        return match ($key) {
            'title'       => $form->title,
            'slug'        => $form->slug,
            'description' => $form->description ?? '',
            default       => $original,
        };
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $values
     */
    private function resolveEntry(array $entry, string $key, string $original, FormDefinition $form, array $values): string
    {
        if ($key === 'fields') {
            return $this->renderFieldsTable($form, $values);
        }
        if (! array_key_exists($key, $entry)) {
            return $original;
        }
        $value = $entry[$key];
        return is_scalar($value) ? (string) $value : $original;
    }

    private function resolveCustom(string $namespace, string $key, string $original): string
    {
        if (! isset($this->providers[$namespace])) {
            return $original;
        }
        $value = ($this->providers[$namespace])($key);
        return $value === null ? $original : $value;
    }

    /**
     * Render every visible field as an inline-styled HTML key/value table.
     *
     * Skips fields whose `type` is `hidden`. Empty values render as an
     * em-dash so the recipient can tell a field exists but wasn't filled in.
     * Inline styles match the Postmark/Cerberus transactional aesthetic
     * (40/60 split, right-aligned values, neutral grey palette) so the
     * table looks reasonable inside any HTML email shell.
     *
     * @param array<string, mixed> $values
     */
    private function renderFieldsTable(FormDefinition $form, array $values): string
    {
        $rows = [];
        foreach ($form->getAllFields() as $field) {
            if ($field->type === 'hidden') {
                continue;
            }
            $label = htmlspecialchars($field->label ?? $field->name, ENT_QUOTES, 'UTF-8');
            $value = $this->renderFieldValue($field, $values[$field->name] ?? null);
            $rows[] = '<tr>'
                . '<td width="40%" style="width: 40%; padding: 10px 16px 10px 0; vertical-align: top; color: #51545E; font-size: 15px; line-height: 1.4;">' . $label . '</td>'
                . '<td width="60%" align="right" style="width: 60%; padding: 10px 0; vertical-align: top; text-align: right; color: #51545E; font-size: 15px; line-height: 1.4;">' . $value . '</td>'
                . '</tr>';
        }

        if ($rows === []) {
            return '';
        }

        return '<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="width: 100%; border-collapse: collapse;">'
            . implode('', $rows)
            . '</table>';
    }

    private function renderFieldValue(FieldDefinition $field, mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '&mdash;';
        }
        if (is_array($value)) {
            $flat = array_filter($value, static fn ($v): bool => is_scalar($v));
            if ($flat === []) {
                return '&mdash;';
            }
            $value = implode(', ', array_map(static fn ($v): string => (string) $v, $flat));
        }
        if (! is_scalar($value)) {
            return '&mdash;';
        }

        $string = (string) $value;

        return match ($field->type) {
            'checkbox' => ($string === '' || $string === '0') ? 'No' : 'Yes',
            'textarea' => nl2br(htmlspecialchars($string, ENT_QUOTES, 'UTF-8')),
            default    => htmlspecialchars($string, ENT_QUOTES, 'UTF-8'),
        };
    }
}
