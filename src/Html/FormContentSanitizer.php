<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Html;

use DOMDocument;
use DOMElement;

use function array_reverse;
use function explode;
use function in_array;
use function iterator_to_array;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function preg_match;
use function preg_replace;
use function strlen;
use function strtolower;
use function substr;
use function trim;

use const LIBXML_HTML_NODEFDTD;
use const LIBXML_HTML_NOIMPLIED;

/**
 * Sanitizer for form-builder content blocks.
 *
 * Sister to {@see InlineHtmlSanitizer} but permits common block-level
 * structure (paragraphs, headings, lists, blockquotes) on top of the
 * inline allow-list. Used when a {@see \Contenir\FormBuilder\FieldType\ContentField}
 * persists its `options['html']` body, and when {@see host renderer}
 * renders that body inline in the form.
 *
 * Allow-list:
 *   - block tags : p, h2, h3, h4, ul, ol, li, blockquote
 *   - inline tags: a, strong, em, b, i, br, code, span, small, sub, sup
 *   - attrs      : a[href|title|rel|target], any[class]
 *   - href schemes: http(s), mailto, tel, relative paths
 *
 * Disallowed tags lose their wrapper but keep their text content
 * (matches InlineHtmlSanitizer's unwrap-on-strip behaviour).
 * scripts / styles / iframes / forms / event handlers (on*) /
 * javascript: URLs are stripped entirely.
 *
 * @api
 */
final class FormContentSanitizer
{
    private const array ALLOWED_TAGS = [
        // Block
        'p',
        'h2',
        'h3',
        'h4',
        'ul',
        'ol',
        'li',
        'blockquote',
        // Inline
        'a',
        'strong',
        'em',
        'b',
        'i',
        'br',
        'code',
        'span',
        'small',
        'sub',
        'sup',
    ];

    /** @var array<string, list<string>> */
    private const array ALLOWED_ATTRS_BY_TAG = [
        'a' => ['href', 'title', 'rel', 'target'],
    ];

    /** @var list<string> */
    private const array ALLOWED_ATTRS_ANY = ['class'];

    private const array SAFE_HREF_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /** Elements removed together with their content. */
    private const array REMOVED_TAGS = ['script', 'style', 'iframe', 'form'];

    private const string ROOT_OPEN = '<div id="__root__">';

    private const string ROOT_CLOSE = '</div>';

    /**
     * Elements are visited in reverse document order, so every descendant is
     * cleaned before the ancestor that may unwrap or remove it.
     */
    public static function sanitize(string $html): string
    {
        $doc                     = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput       = false;
        $doc->preserveWhiteSpace = true;

        $previousState = libxml_use_internal_errors(use_errors: true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8">' . self::ROOT_OPEN . $html . self::ROOT_CLOSE,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previousState);

        $root = $doc->getElementById('__root__');
        if (! $root instanceof DOMElement) {
            // The parser always keeps the wrapper it was given, so the lookup cannot miss.
            return ''; // @codeCoverageIgnore
        }

        $elements = iterator_to_array($root->getElementsByTagName('*'));
        foreach (array_reverse($elements) as $element) {
            self::sanitizeElement($element);
        }

        $output = (string) $doc->saveHTML($root);

        return trim(substr($output, strlen(self::ROOT_OPEN), -strlen(self::ROOT_CLOSE)));
    }

    /**
     * Browsers ignore ASCII tab and newline anywhere in a URL and strip
     * leading C0 controls and spaces, so `java&#9;script:` still runs as
     * `javascript:`. They are removed before the scheme is checked.
     */
    private static function isSafeHref(string $value): bool
    {
        $value = (string) preg_replace('/[\x00-\x20]+/', replacement: '', subject: $value);
        if ('' === $value) {
            return false;
        }

        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.\-]*:/', $value) !== 1) {
            return true;
        }

        $scheme = strtolower(explode(':', $value, limit: 2)[0]);

        return in_array($scheme, self::SAFE_HREF_SCHEMES, strict: true);
    }

    private static function sanitizeElement(DOMElement $element): void
    {
        $tag = strtolower($element->tagName);
        if (in_array($tag, self::REMOVED_TAGS, strict: true)) {
            $element->parentNode?->removeChild($element);
            return;
        }

        if (! in_array($tag, self::ALLOWED_TAGS, strict: true)) {
            self::unwrap($element);
            return;
        }

        self::stripDisallowedAttributes($element, $tag);
    }

    private static function stripDisallowedAttributes(DOMElement $element, string $tag): void
    {
        $allowed = [
            ...self::ALLOWED_ATTRS_ANY,
            ...(self::ALLOWED_ATTRS_BY_TAG[$tag] ?? []),
        ];

        /** @var list<string> $names */
        $names = $element->getAttributeNames();
        foreach ($names as $name) {
            $lower = strtolower($name);
            if (! in_array($lower, $allowed, strict: true)) {
                $element->removeAttribute($name);
                continue;
            }

            if ('href' === $lower && ! self::isSafeHref($element->getAttribute($name))) {
                $element->removeAttribute($name);
            }
        }
    }

    private static function unwrap(DOMElement $element): void
    {
        while (null !== $element->firstChild) {
            $element->before($element->firstChild);
        }

        $element->remove();
    }
}
