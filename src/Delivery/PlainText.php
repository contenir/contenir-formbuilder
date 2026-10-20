<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Delivery;

use function explode;
use function html_entity_decode;
use function implode;
use function preg_replace;
use function strip_tags;
use function trim;

use const ENT_HTML5;
use const ENT_QUOTES;

/**
 * A readable plain-text version of an HTML email body, for its text
 * alternative: a line break where a row, paragraph, heading, list item or
 * <br> ends, a tab between table cells, no tags, entities decoded, and no
 * blank lines. Not a rendering, just no markup with the structure roughly
 * kept.
 *
 * @internal
 */
final class PlainText
{
    public static function fromHtml(string $html): string
    {
        $text = preg_replace('/<\/(tr|p|div|h[1-6]|li)\s*>|<br\s*\/?>/i', replacement: "\n", subject: $html) ?? $html;
        $text = preg_replace('/<\/(td|th)\s*>/i', replacement: "\t", subject: $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, encoding: 'UTF-8');

        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim(preg_replace('/[ \t]+/', replacement: ' ', subject: $line) ?? $line);
            if ('' === $line) {
                continue;
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }
}
