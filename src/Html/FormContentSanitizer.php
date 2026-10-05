<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Html;

use DOMDocument;
use DOMElement;
use DOMNode;

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
 */
final class FormContentSanitizer
{
    private const ALLOWED_TAGS = [
        // Block
        'p', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote',
        // Inline
        'a', 'strong', 'em', 'b', 'i', 'br', 'code', 'span', 'small', 'sub', 'sup',
    ];

    private const ALLOWED_ATTRS_BY_TAG = [
        'a' => ['href', 'title', 'rel', 'target'],
    ];

    private const ALLOWED_ATTRS_ANY = ['class'];

    private const SAFE_HREF_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public static function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $doc                     = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput       = false;
        $doc->preserveWhiteSpace = true;

        $previousState = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><div id="__root__">' . $html . '</div>',
            \LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previousState);

        $root = $doc->getElementById('__root__');
        if (! $root instanceof DOMElement) {
            return '';
        }

        self::walk($root);

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $doc->saveHTML($child);
        }

        return trim($output);
    }

    private static function walk(DOMNode $node): void
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            self::walk($child);

            $tag = strtolower($child->tagName);

            if ($tag === 'script' || $tag === 'style' || $tag === 'iframe' || $tag === 'form') {
                $child->parentNode?->removeChild($child);
                continue;
            }

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                self::unwrap($child);
                continue;
            }

            self::stripDisallowedAttributes($child, $tag);
        }
    }

    private static function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;
        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }
        $parent->removeChild($element);
    }

    private static function stripDisallowedAttributes(DOMElement $element, string $tag): void
    {
        $allowed = array_merge(
            self::ALLOWED_ATTRS_ANY,
            self::ALLOWED_ATTRS_BY_TAG[$tag] ?? [],
        );

        $names = [];
        foreach ($element->attributes as $attr) {
            $names[] = $attr->nodeName;
        }

        foreach ($names as $name) {
            $lower = strtolower($name);
            if (! in_array($lower, $allowed, true)) {
                $element->removeAttribute($name);
                continue;
            }

            if ($lower === 'href' && ! self::isSafeHref((string) $element->getAttribute('href'))) {
                $element->removeAttribute($name);
            }
        }
    }

    /**
     * Browsers ignore ASCII tab and newline anywhere in a URL and strip
     * leading C0 controls and spaces, so `java&#9;script:` still runs as
     * `javascript:`. They are removed before the scheme is checked.
     */
    private static function isSafeHref(string $value): bool
    {
        $value = (string) preg_replace('/[\x00-\x20]+/', '', $value);
        if ($value === '') {
            return false;
        }

        if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.\-]*:/', $value) !== 1) {
            return true;
        }

        $scheme = strtolower(substr($value, 0, (int) strpos($value, ':')));
        return in_array($scheme, self::SAFE_HREF_SCHEMES, true);
    }
}
