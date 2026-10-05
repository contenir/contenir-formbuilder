<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Service;

use Contenir\FormBuilder\Definition\FieldDefinition;
use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Definition\GroupDefinition;
use Contenir\FormBuilder\Definition\RowDefinition;
use Contenir\FormBuilder\Definition\SectionDefinition;
use Contenir\FormBuilder\Service\TokenReplacer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class TokenReplacerTest extends TestCase
{
    public function testReplacesFieldFormAndEntryTokens(): void
    {
        $replacer = new TokenReplacer();
        $form     = new FormDefinition(id: 1, slug: 'contact', title: 'Contact us', description: 'Get in touch');

        $result = $replacer->replace(
            "Hi {field:name}, your {form:title} entry #{entry:id} on {entry:date}",
            $form,
            ['name' => 'Alice'],
            ['id' => 42, 'date' => '2026-05-08'],
        );

        self::assertSame('Hi Alice, your Contact us entry #42 on 2026-05-08', $result);
    }

    public function testLeavesUnknownTokensIntactToSurfaceTypos(): void
    {
        $replacer = new TokenReplacer();
        $form     = new FormDefinition(id: 1, slug: 'x', title: 'X');

        $result = $replacer->replace('Hello {field:missing} {form:nope}', $form, [], []);

        self::assertSame('Hello {field:missing} {form:nope}', $result);
    }

    public function testFlattensArrayFieldValues(): void
    {
        $replacer = new TokenReplacer();
        $form     = new FormDefinition(id: 1, slug: 'x', title: 'X');

        $result = $replacer->replace(
            'Tags: {field:tags}',
            $form,
            ['tags' => ['php', 'forms', 'cms']],
            [],
        );

        self::assertSame('Tags: php, forms, cms', $result);
    }

    public function testCustomNamespaceProvider(): void
    {
        $replacer = new TokenReplacer(['admin_url' => 'https://admin.example']);
        $form     = new FormDefinition(id: 1, slug: 'x', title: 'X');

        $result = $replacer->replace('Visit {site:admin_url}', $form, [], []);

        self::assertSame('Visit https://admin.example', $result);
    }

    public function testSiteContextWithMissingKeyPreservesToken(): void
    {
        $replacer = new TokenReplacer(['admin_url' => 'https://admin.example']);
        $form     = new FormDefinition(id: 1, slug: 'x', title: 'X');

        $result = $replacer->replace('Visit {site:not_set}', $form, [], []);

        self::assertSame('Visit {site:not_set}', $result);
    }

    public function testSiteContextWithNullValueRendersEmpty(): void
    {
        $replacer = new TokenReplacer(['telephone' => null]);
        $form     = new FormDefinition(id: 1, slug: 'x', title: 'X');

        $result = $replacer->replace('Call {site:telephone} today', $form, [], []);

        self::assertSame('Call  today', $result);
    }

    public function testCustomResolverReturningNullPreservesToken(): void
    {
        $replacer = new TokenReplacer();
        $replacer->register('settings', static fn (string $key): ?string => null);
        $form = new FormDefinition(id: 1, slug: 'x', title: 'X');

        $result = $replacer->replace('Hello {settings:foo}', $form, [], []);

        self::assertSame('Hello {settings:foo}', $result);
    }

    public function testCustomResolverReturningEmptyStringRendersEmpty(): void
    {
        $replacer = new TokenReplacer();
        $replacer->register('settings', static fn (string $key): ?string => '');
        $form = new FormDefinition(id: 1, slug: 'x', title: 'X');

        $result = $replacer->replace('Hello {settings:foo}', $form, [], []);

        self::assertSame('Hello ', $result);
    }

    public function testReplaceForUrlEncodesResolvedValues(): void
    {
        $replacer = new TokenReplacer();
        $form     = new FormDefinition(id: 1, slug: 'contact', title: 'Contact');

        $result = $replacer->replaceForUrl(
            'https://example.com/thanks?email={field:email}&name={field:name}',
            $form,
            ['email' => 'a@b.co', 'name' => 'Alice & Bob'],
            [],
        );

        // `@` in `a@b.co` becomes `%40`; ampersand and space in `Alice & Bob`
        // become `%20%26%20` — without this encoding the ampersand would
        // close the `name=` query parameter and inject a new one.
        self::assertSame(
            'https://example.com/thanks?email=a%40b.co&name=Alice%20%26%20Bob',
            $result,
        );
    }

    public function testReplaceForUrlLeavesUnresolvedTokensIntact(): void
    {
        $replacer = new TokenReplacer();
        $form     = new FormDefinition(id: 1, slug: 'x', title: 'X');

        // {field:missing} can't resolve — falls through; {form:title} resolves
        // to "X" (no encoding needed since it's already URL-safe).
        $result = $replacer->replaceForUrl(
            'https://x.test/?missing={field:missing}&title={form:title}',
            $form,
            [],
            [],
        );

        self::assertSame('https://x.test/?missing={field:missing}&title=X', $result);
    }

    public function testReplaceForUrlEncodesPathTraversalAttempts(): void
    {
        $replacer = new TokenReplacer();
        $form     = new FormDefinition(id: 1, slug: 'x', title: 'X');

        $result = $replacer->replaceForUrl(
            '/profile/{field:slug}',
            $form,
            ['slug' => '../admin'],
            [],
        );

        // `/` in the substituted value gets encoded as `%2F` — prevents a
        // submitted value from escaping the URL path component.
        self::assertSame('/profile/..%2Fadmin', $result);
    }

    public function testEntryFieldsExpandsToHtmlTableOfLabelsAndValues(): void
    {
        $replacer = new TokenReplacer();
        $form     = $this->formWithFields([
            new FieldDefinition(id: 1, type: 'text', name: 'first_name', label: 'First name'),
            new FieldDefinition(id: 2, type: 'email', name: 'email', label: 'Email'),
            new FieldDefinition(id: 3, type: 'textarea', name: 'comments', label: 'Comments'),
            new FieldDefinition(id: 4, type: 'checkbox', name: 'subscribe', label: 'Subscribe'),
        ]);

        $result = $replacer->replace(
            '<body>{entry:fields}</body>',
            $form,
            [
                'first_name' => 'Alice',
                'email'      => 'alice@example.com',
                'comments'   => "line one\nline two",
                'subscribe'  => '1',
            ],
        );

        self::assertStringContainsString('<table', $result);
        self::assertStringContainsString('First name', $result);
        self::assertStringContainsString('Alice', $result);
        self::assertStringContainsString('alice@example.com', $result);
        // Textarea values get nl2br applied so multi-line input retains breaks.
        self::assertStringContainsString("line one<br />\nline two", $result);
        // Checkbox values render as Yes/No, not 1/0.
        self::assertStringContainsString('>Yes<', $result);
    }

    public function testEntryFieldsRendersEmptyValuesAsEmDash(): void
    {
        $replacer = new TokenReplacer();
        $form     = $this->formWithFields([
            new FieldDefinition(id: 1, type: 'text', name: 'name', label: 'Name'),
            new FieldDefinition(id: 2, type: 'text', name: 'phone', label: 'Phone'),
        ]);

        $result = $replacer->replace('{entry:fields}', $form, ['name' => 'Alice']);

        // Phone wasn't submitted — the row still appears, with an em-dash so
        // the recipient can tell the field exists but wasn't filled in.
        self::assertStringContainsString('Phone', $result);
        self::assertStringContainsString('&mdash;', $result);
    }

    public function testEntryFieldsSkipsHiddenFields(): void
    {
        $replacer = new TokenReplacer();
        $form     = $this->formWithFields([
            new FieldDefinition(id: 1, type: 'text', name: 'name', label: 'Name'),
            new FieldDefinition(id: 2, type: 'hidden', name: 'utm_source', label: 'UTM source'),
        ]);

        $result = $replacer->replace('{entry:fields}', $form, [
            'name'       => 'Alice',
            'utm_source' => 'newsletter',
        ]);

        self::assertStringContainsString('Name', $result);
        self::assertStringNotContainsString('UTM source', $result);
        self::assertStringNotContainsString('newsletter', $result);
    }

    public function testEntryFieldsEscapesHtmlInValues(): void
    {
        $replacer = new TokenReplacer();
        $form     = $this->formWithFields([
            new FieldDefinition(id: 1, type: 'text', name: 'name', label: 'Name'),
        ]);

        $result = $replacer->replace(
            '{entry:fields}',
            $form,
            ['name' => '<script>alert(1)</script>'],
        );

        // Submitted values must be escaped — the raw <script> tag would
        // execute in webmail clients that render HTML email.
        self::assertStringNotContainsString('<script>', $result);
        self::assertStringContainsString('&lt;script&gt;', $result);
    }

    public function testEntryFieldsReturnsEmptyStringWhenFormHasNoFields(): void
    {
        $replacer = new TokenReplacer();
        $form     = new FormDefinition(id: 1, slug: 'x', title: 'X');

        $result = $replacer->replace('before {entry:fields} after', $form, []);

        self::assertSame('before  after', $result);
    }

    /**
     * @return array<string, array{string, array<string, mixed>, array<string, mixed>, string}>
     */
    public static function htmlEscapingProvider(): array
    {
        return [
            'markup in a field'         => [
                '<p>{field:name}</p>',
                ['name' => '<script>alert(1)</script>'],
                [],
                '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>',
            ],
            'attribute breakout'        => [
                '<a title="{field:name}">x</a>',
                ['name' => '" onmouseover="x'],
                [],
                '<a title="&quot; onmouseover=&quot;x">x</a>',
            ],
            'single quotes'             => [
                "<a title='{field:name}'>x</a>",
                ['name' => "' onclick='x"],
                [],
                "<a title='&#039; onclick=&#039;x'>x</a>",
            ],
            'ampersand'                 => ['{field:name}', ['name' => 'Tom & Jerry'], [], 'Tom &amp; Jerry'],
            'multi-value field'         => ['{field:tags}', ['tags' => ['<b>', '<i>']], [], '&lt;b&gt;, &lt;i&gt;'],
            'entry attribute'           => ['{entry:ip}', [], ['ip' => '<img src=x>'], '&lt;img src=x&gt;'],
            'invalid UTF-8 is replaced' => ['{field:name}', ['name' => "a\xC3\x28b"], [], "a\u{FFFD}(b"],
            'unknown token left as-is'  => ['{field:missing}', [], [], '{field:missing}'],
            'plain value unchanged'     => ['{field:name}', ['name' => 'Alice'], [], 'Alice'],
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $entry
     */
    #[DataProvider('htmlEscapingProvider')]
    public function testReplaceForHtmlEscapesResolvedValues(
        string $template,
        array $values,
        array $entry,
        string $expected,
    ): void {
        $result = (new TokenReplacer())->replaceForHtml($template, $this->formWithFields([]), $values, $entry);

        self::assertSame($expected, $result);
    }

    public function testReplaceForHtmlDoesNotDoubleEscapeTheFieldsTable(): void
    {
        $form = $this->formWithFields([
            new FieldDefinition(id: 1, type: 'text', name: 'name', label: 'Name'),
        ]);

        $result = (new TokenReplacer())->replaceForHtml('{entry:fields}', $form, ['name' => '<b>Bob</b>']);

        self::assertSame((new TokenReplacer())->replace('{entry:fields}', $form, ['name' => '<b>Bob</b>']), $result);
        self::assertStringContainsString('&lt;b&gt;Bob&lt;/b&gt;', $result);
        self::assertStringNotContainsString('&amp;lt;', $result);
    }

    public function testReplaceForHtmlMatchesTheFieldsTableTokenCaseInsensitively(): void
    {
        $form = $this->formWithFields([
            new FieldDefinition(id: 1, type: 'text', name: 'name', label: 'Name'),
        ]);

        $result = (new TokenReplacer())->replaceForHtml('{ENTRY:fields}', $form, ['name' => 'Bob']);

        self::assertStringStartsWith('<table', $result);
    }

    public function testReplaceForHtmlEscapesCustomNamespaceValues(): void
    {
        $replacer = new TokenReplacer();
        $replacer->register('crm', static fn (string $key): string => "<{$key}>");

        self::assertSame('&lt;owner&gt;', $replacer->replaceForHtml('{crm:owner}', $this->formWithFields([]), []));
    }

    public function testReplaceForHtmlEscapesFormAndSiteValues(): void
    {
        $replacer = new TokenReplacer(['base_url' => 'https://example.com/?a=1&b=2']);
        $form     = new FormDefinition(id: 1, slug: 'contact', title: 'Q&A <draft>');

        $result = $replacer->replaceForHtml('{form:title} {site:base_url}', $form, []);

        self::assertSame('Q&amp;A &lt;draft&gt; https://example.com/?a=1&amp;b=2', $result);
    }

    public function testReplaceLeavesValuesUnescaped(): void
    {
        $result = (new TokenReplacer())->replace('{field:name}', $this->formWithFields([]), ['name' => '<b>&</b>']);

        self::assertSame('<b>&</b>', $result);
    }

    public function testReplaceForUrlStillEncodesTheFieldsTable(): void
    {
        $form = $this->formWithFields([
            new FieldDefinition(id: 1, type: 'text', name: 'name', label: 'Name'),
        ]);

        $result = (new TokenReplacer())->replaceForUrl('{entry:fields}', $form, ['name' => 'Bob']);

        self::assertStringStartsWith('%3Ctable', $result);
    }

    /**
     * @param list<FieldDefinition> $fields
     */
    private function formWithFields(array $fields): FormDefinition
    {
        return new FormDefinition(
            id:    1,
            slug:  'contact',
            title: 'Contact',
            sections: [
                new SectionDefinition(
                    id:   1,
                    key:  'main',
                    groups: [
                        new GroupDefinition(
                            id:   1,
                            rows: [
                                new RowDefinition(id: 1, fields: $fields),
                            ],
                        ),
                    ],
                ),
            ],
        );
    }
}
