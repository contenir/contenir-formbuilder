<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Delivery;

use Contenir\FormBuilder\Delivery\PlainText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class PlainTextTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function htmlProvider(): array
    {
        return [
            'empty'               => ['', ''],
            'plain text kept'     => ['Hello there', 'Hello there'],
            'paragraphs on lines' => ['<p>One</p><p>Two</p>', "One\nTwo"],
            'breaks and headings' => ['<h2>Title</H2>A<br>B<br />C', "Title\nA\nB\nC"],
            'divs and list items' => ['<div>A</div><ul><li>B</li ><li>C</li></ul>', "A\nB\nC"],
            'table cells tabbed'  => [
                '<table><tr><th>Name</th><td>Ann</td></tr><tr><td>Age</td><td>9</td></tr></table>',
                "Name Ann\nAge 9",
            ],
            'entities decoded'    => ['<p>Fish &amp; chips &quot;now&quot; &hellip;</p>', 'Fish & chips "now" …'],
            'spaces collapsed'    => ["<p>  A   \t B  </p>", 'A B'],
            'blank lines dropped' => ["<p>A</p>\r\n\r\n<p></p><p>B</p>", "A\nB"],
        ];
    }

    #[Test]
    #[DataProvider('htmlProvider')]
    public function readsHtmlAsText(string $html, string $text): void
    {
        static::assertSame($text, PlainText::fromHtml($html));
    }
}
