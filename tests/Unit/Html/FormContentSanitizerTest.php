<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Html;

use Contenir\FormBuilder\Html\FormContentSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function libxml_clear_errors;
use function libxml_get_errors;
use function libxml_use_internal_errors;

#[Group('unit')]
final class FormContentSanitizerTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function htmlProvider(): array
    {
        return [
            'empty input'                      => ['', ''],
            'whitespace only'                  => [" \n\t ", ''],
            'plain text'                       => ['Read carefully.', 'Read carefully.'],
            'allowed block and inline tags'    => [
                '<h2>Title</h2><p>A <strong>b</strong> <em>c</em><br>d</p><ul><li>x</li></ul>',
                '<h2>Title</h2><p>A <strong>b</strong> <em>c</em><br>d</p><ul><li>x</li></ul>',
            ],
            'class kept on any allowed tag'    => ['<p class="lead">x</p>', '<p class="lead">x</p>'],
            'event handlers and style dropped' => ['<p onclick="x()" style="color:red" id="p">x</p>', '<p>x</p>'],
            'script removed with content'      => ['<p>a<script>alert(1)</script>b</p>', '<p>ab</p>'],
            'style iframe form removed'        => [
                '<style>p{}</style><iframe src="x"></iframe><form><input></form>ok',
                'ok',
            ],
            'disallowed tags unwrapped'        => [
                '<div><font color="red">kept <b>bold</b></font></div>',
                'kept <b>bold</b>',
            ],
            'nested disallowed tags'           => ['<section><div><span>x</span></div></section>', '<span>x</span>'],
            'anchor attributes kept'           => [
                '<a href="https://example.com" title="T" rel="noopener" target="_blank">x</a>',
                '<a href="https://example.com" title="T" rel="noopener" target="_blank">x</a>',
            ],
            'href only allowed on anchors'     => ['<span href="https://x">x</span>', '<span>x</span>'],
            'relative href kept'               => ['<a href="/contact">x</a>', '<a href="/contact">x</a>'],
            'mailto and tel kept'              => [
                '<a href="mailto:a@b.c">m</a><a href="tel:+61">t</a>',
                '<a href="mailto:a@b.c">m</a><a href="tel:+61">t</a>',
            ],
            'upper-case safe scheme kept'      => [
                '<a href="HTTPS://example.com">x</a>',
                '<a href="HTTPS://example.com">x</a>',
            ],
            'relative href with a colon kept'  => ['<a href="/search?q=a:b">x</a>', '<a href="/search?q=a:b">x</a>'],
            'javascript href removed'          => ['<a href="javascript:alert(1)">x</a>', '<a>x</a>'],
            'upper-case scheme removed'        => ['<a href="JaVaScRiPt:alert(1)">x</a>', '<a>x</a>'],
            'tab inside scheme removed'        => ['<a href="java&#9;script:alert(1)">x</a>', '<a>x</a>'],
            'leading control chars removed'    => ['<a href="&#1; javascript:alert(1)">x</a>', '<a>x</a>'],
            'data uri removed'                 => ['<a href="data:text/html,x">x</a>', '<a>x</a>'],
            'empty href removed'               => ['<a href=" ">x</a>', '<a>x</a>'],
            'utf-8 preserved'                  => ['<p>Café — ✓</p>', '<p>Café — ✓</p>'],
            'stray closing wrapper ignored'    => ['a</div><script>x</script>b', 'a'],
        ];
    }

    #[Test]
    public function clearsTheParserErrorsItCaused(): void
    {
        $previous = libxml_use_internal_errors(use_errors: true);
        libxml_clear_errors();

        try {
            FormContentSanitizer::sanitize('<p>broken <b>markup</p></i>');
            static::assertSame([], libxml_get_errors());
        } finally {
            libxml_use_internal_errors($previous);
        }
    }

    #[Test]
    #[DataProvider('htmlProvider')]
    public function keepsOnlyTheAllowList(string $html, string $expected): void
    {
        static::assertSame($expected, FormContentSanitizer::sanitize($html));
    }

    #[Test]
    public function restoresTheCallersLibxmlErrorHandling(): void
    {
        $previous = libxml_use_internal_errors(use_errors: false);

        try {
            FormContentSanitizer::sanitize('<p>broken <b>markup</p></i>');
            static::assertFalse(libxml_use_internal_errors());
        } finally {
            libxml_use_internal_errors($previous);
        }
    }
}
