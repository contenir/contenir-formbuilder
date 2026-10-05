<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Service;

use Contenir\FormBuilder\Definition\FieldDefinition;
use Contenir\FormBuilder\Definition\FormDefinition;

use function array_key_exists;
use function htmlspecialchars;
use function implode;
use function is_array;
use function is_scalar;
use function nl2br;
use function preg_replace_callback;
use function rawurlencode;
use function sprintf;
use function strtolower;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;

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
 *
 * @api
 *
 * @mago-expect lint:cyclomatic-complexity Kept whole for 2.0 (one resolver per token namespace); splitting it is a proposed follow-up.
 * @mago-expect lint:kan-defect Kept whole for 2.0 (one resolver per token namespace); splitting it is a proposed follow-up.
 * @mago-expect lint:too-many-methods Kept whole for 2.0 (one resolver per token namespace); splitting it is a proposed follow-up.
 */
final class TokenReplacer
{
    private const string FIELDS_ROW =
        '<tr>'
            . '<td width="40%%" style="width: 40%%; padding: 10px 16px 10px 0; vertical-align: top; color: #51545E; font-size: 15px; line-height: 1.4;">%s</td>'
            . '<td width="60%%" align="right" style="width: 60%%; padding: 10px 0; vertical-align: top; text-align: right; color: #51545E; font-size: 15px; line-height: 1.4;">%s</td>'
            . '</tr>';

    /** @var array<string, callable(string): ?string> */
    private array $providers = [];

    /**
     * @param array<string, mixed> $siteContext  Static values for `site:*` tokens.
     *
     * @mago-expect analysis:mixed-assignment Site context values are untyped; non-scalars resolve to an empty string.
     */
    public function __construct(array $siteContext = [])
    {
        if ([] !== $siteContext) {
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
     * @param array<array-key, mixed> $values
     *
     * @mago-expect analysis:mixed-assignment Multi-value fields hold untyped entries; non-scalars are skipped.
     */
    private static function joinScalars(array $values): string
    {
        $strings = [];
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $strings[] = (string) $value;
        }

        return implode(', ', $strings);
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
            static fn(string $value, string $tag): string => '{entry:fields}' === strtolower($tag)
                ? $value
                : htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, encoding: 'UTF-8'),
        );
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
     *
     * @mago-expect lint:prefer-first-class-callable The callback also receives the matched tag, which rawurlencode(...) would reject as an extra argument.
     */
    public function replaceForUrl(string $template, FormDefinition $form, array $values, array $entry = []): string
    {
        return $this->dispatch(
            $template,
            $form,
            $values,
            $entry,
            static fn(string $value): string => rawurlencode($value),
        );
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $entry
     * @param (callable(string, string): string)|null $postProcess Receives the resolved value and the matched token.
     *
     * @mago-expect analysis:possibly-undefined-string-array-index The pattern's named groups always participate in a match.
     * @mago-expect analysis:possibly-undefined-int-array-index Index 0 always holds the whole match.
     * @mago-expect analysis:possibly-null-argument The match groups read above are always set, so never null.
     */
    private function dispatch(
        string $template,
        FormDefinition $form,
        array $values,
        array $entry,
        ?callable $postProcess,
    ): string {
        if ('' === $template) {
            return '';
        }

        return (string) preg_replace_callback(
            '/\{(?<ns>[a-z]+):(?<key>[a-z0-9_\-\.]+)\}/i',
            /** @param array<array-key, string> $matches */
            function (array $matches) use ($form, $values, $entry, $postProcess): string {
                $namespace = strtolower($matches['ns']);
                $key       = $matches['key'];
                $original  = $matches[0];

                $resolved = match ($namespace) {
                    'field' => $this->resolveField($values, $key, $original),
                    'form'  => $this->resolveForm($form, $key, $original),
                    'entry' => $this->resolveEntry($entry, $key, $original, $form, $values),
                    default => $this->resolveCustom($namespace, $key, $original),
                };

                if (null === $postProcess || $resolved === $original) {
                    return $resolved;
                }
                return $postProcess($resolved, $original);
            },
            $template,
        );
    }

    /**
     * Render every visible field as an inline-styled HTML key/value table.
     *
     * Skips `hidden` fields and `content` blocks, which collect no data. Empty values render as an
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
            if ('hidden' === $field->type || 'content' === $field->type) {
                continue;
            }
            $label  = htmlspecialchars($field->label ?? $field->name, ENT_QUOTES, encoding: 'UTF-8');
            $value  = $this->renderFieldValue($field, $values[$field->name] ?? null);
            $rows[] = sprintf(self::FIELDS_ROW, $label, $value);
        }

        if ([] === $rows) {
            return '';
        }

        return (
            '<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="width: 100%; border-collapse: collapse;">'
                . implode('', $rows)
                . '</table>'
        );
    }

    private function renderFieldValue(FieldDefinition $field, mixed $value): string
    {
        $string = match (true) {
            is_array($value)  => self::joinScalars($value),
            is_scalar($value) => (string) $value,
            default           => '',
        };
        if ('' === $string) {
            return '&mdash;';
        }

        return match ($field->type) {
            'checkbox' => '0' === $string ? 'No' : 'Yes',
            'textarea' => nl2br(htmlspecialchars($string, ENT_QUOTES, encoding: 'UTF-8')),
            default    => htmlspecialchars($string, ENT_QUOTES, encoding: 'UTF-8'),
        };
    }

    private function resolveCustom(string $namespace, string $key, string $original): string
    {
        $provider = $this->providers[$namespace] ?? null;
        if (null === $provider) {
            return $original;
        }

        return $provider($key) ?? $original;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $values
     *
     * @mago-expect analysis:mixed-assignment Entry attributes are untyped; non-scalars leave the token intact.
     */
    private function resolveEntry(
        array $entry,
        string $key,
        string $original,
        FormDefinition $form,
        array $values,
    ): string {
        if ('fields' === $key) {
            return $this->renderFieldsTable($form, $values);
        }
        if (! array_key_exists($key, $entry)) {
            return $original;
        }
        $value = $entry[$key];
        return is_scalar($value) ? (string) $value : $original;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @mago-expect analysis:mixed-assignment Submitted values are untyped; arrays are joined and other non-scalars resolve empty.
     */
    private function resolveField(array $values, string $key, string $original): string
    {
        if (! array_key_exists($key, $values)) {
            return $original;
        }
        $value = $values[$key];
        if (is_array($value)) {
            return self::joinScalars($value);
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
}
