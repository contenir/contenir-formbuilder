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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[Group('unit')]
final class TokenReplacerTest extends TestCase
{
    /**
     * @return array<string, array{mixed, string}>
     */
    public static function entryValueProvider(): array
    {
        return [
            'scalar entry value'     => [42, 'Entry 42'],
            'non-scalar entry value' => [['x'], 'Entry {entry:id}'],
        ];
    }

    /**
     * @return array<string, array{string, mixed, string}>
     */
    public static function fieldValueRenderingProvider(): array
    {
        return [
            'checkbox ticked'         => ['checkbox', '1', 'Yes'],
            'checkbox unticked'       => ['checkbox', '0', 'No'],
            'textarea keeps newlines' => ['textarea', "a\n<b>", "a<br />\n&lt;b&gt;"],
            'list of scalars'         => ['multiselect', ['a', ['x'], 'b'], 'a, b'],
            'list without scalars'    => ['multiselect', [['x']], '&mdash;'],
            'object value'            => ['text', new stdClass(), '&mdash;'],
        ];
    }

    /**
     * @param list<FieldDefinition> $fields
     */
    /**
     * @return array<string, array{string, string}>
     */
    public static function formTokenProvider(): array
    {
        return [
            'slug'                => ['{form:slug}', 'contact'],
            'title'               => ['{form:title}', 'Contact'],
            'missing description' => ['{form:description}', ''],
            'unknown attribute'   => ['{form:colour}', '{form:colour}'],
            'unknown namespace'   => ['{nope:x}', '{nope:x}'],
            'unknown entry key'   => ['{entry:colour}', '{entry:colour}'],
        ];
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

    #[Test]
    public function customNamespaceProvider(): void
    {
        $replacer = new TokenReplacer(['admin_url' => 'https://admin.example']);
        $form     = new FormDefinition(
            id: 1,
            slug: 'x',
            title: 'X',
        );

        $result = $replacer->replace('Visit {site:admin_url}', $form, [], []);

        static::assertSame('Visit https://admin.example', $result);
    }

    #[Test]
    public function customResolverReturningEmptyStringRendersEmpty(): void
    {
        $replacer = new TokenReplacer();
        $replacer->register('settings', static fn(string $key): ?string => '');
        $form = new FormDefinition(
            id: 1,
            slug: 'x',
            title: 'X',
        );

        $result = $replacer->replace('Hello {settings:foo}', $form, [], []);

        static::assertSame('Hello ', $result);
    }

    #[Test]
    public function customResolverReturningNullPreservesToken(): void
    {
        $replacer = new TokenReplacer();
        $replacer->register('settings', static fn(string $key): ?string => null);
        $form = new FormDefinition(
            id: 1,
            slug: 'x',
            title: 'X',
        );

        $result = $replacer->replace('Hello {settings:foo}', $form, [], []);

        static::assertSame('Hello {settings:foo}', $result);
    }

    #[Test]
    public function emptyTemplateStaysEmpty(): void
    {
        static::assertSame('', (new TokenReplacer(['a' => 'b']))->replace('', $this->formWithFields([]), []));
    }

    #[Test]
    public function entryFieldsEscapesHtmlInValues(): void
    {
        $replacer = new TokenReplacer();
        $form     = $this->formWithFields([
            new FieldDefinition(
                id: 1,
                type: 'text',
                name: 'name',
                label: 'Name',
            ),
        ]);

        $result = $replacer->replace(
            '{entry:fields}',
            $form,
            ['name' => '<script>alert(1)</script>'],
        );

        // Submitted values must be escaped — the raw <script> tag would
        // execute in webmail clients that render HTML email.
        static::assertStringNotContainsString('<script>', $result);
        static::assertStringContainsString('&lt;script&gt;', $result);
    }

    #[Test]
    public function entryFieldsExpandsToHtmlTableOfLabelsAndValues(): void
    {
        $replacer = new TokenReplacer();
        $form     = $this->formWithFields([
            new FieldDefinition(
                id: 1,
                type: 'text',
                name: 'first_name',
                label: 'First name',
            ),
            new FieldDefinition(
                id: 2,
                type: 'email',
                name: 'email',
                label: 'Email',
            ),
            new FieldDefinition(
                id: 3,
                type: 'textarea',
                name: 'comments',
                label: 'Comments',
            ),
            new FieldDefinition(
                id: 4,
                type: 'checkbox',
                name: 'subscribe',
                label: 'Subscribe',
            ),
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

        static::assertStringContainsString('<table', $result);
        static::assertStringContainsString('First name', $result);
        static::assertStringContainsString('Alice', $result);
        static::assertStringContainsString('alice@example.com', $result);
        // Textarea values get nl2br applied so multi-line input retains breaks.
        static::assertStringContainsString("line one<br />\nline two", $result);
        // Checkbox values render as Yes/No, not 1/0.
        static::assertStringContainsString('>Yes<', $result);
    }

    #[Test]
    public function entryFieldsFallsBackToTheFieldName(): void
    {
        $form = $this->formWithFields([new FieldDefinition(
            id: 1,
            type: 'text',
            name: 'nickname',
        )]);
        $result = (new TokenReplacer())->replace('{entry:fields}', $form, ['nickname' => 'Al']);

        static::assertStringContainsString('>nickname</td>', $result);
    }

    #[Test]
    public function entryFieldsRendersEmptyValuesAsEmDash(): void
    {
        $replacer = new TokenReplacer();
        $form     = $this->formWithFields([
            new FieldDefinition(
                id: 1,
                type: 'text',
                name: 'name',
                label: 'Name',
            ),
            new FieldDefinition(
                id: 2,
                type: 'text',
                name: 'phone',
                label: 'Phone',
            ),
        ]);

        $result = $replacer->replace('{entry:fields}', $form, ['name' => 'Alice']);

        // Phone wasn't submitted — the row still appears, with an em-dash so
        // the recipient can tell the field exists but wasn't filled in.
        static::assertStringContainsString('Phone', $result);
        static::assertStringContainsString('&mdash;', $result);
    }

    #[Test]
    #[DataProvider('fieldValueRenderingProvider')]
    public function entryFieldsRendersValuesByFieldType(string $type, mixed $value, string $expected): void
    {
        $form = $this->formWithFields([new FieldDefinition(
            id: 1,
            type: $type,
            name: 'answer',
            label: 'Answer',
        )]);
        $result = (new TokenReplacer())->replace('{entry:fields}', $form, ['answer' => $value]);

        static::assertStringContainsString(">{$expected}</td>", $result);
    }

    #[Test]
    public function entryFieldsReturnsEmptyStringWhenFormHasNoFields(): void
    {
        $replacer = new TokenReplacer();
        $form     = new FormDefinition(
            id: 1,
            slug: 'x',
            title: 'X',
        );

        $result = $replacer->replace('before {entry:fields} after', $form, []);

        static::assertSame('before  after', $result);
    }

    #[Test]
    public function entryFieldsSkipsContentBlocks(): void
    {
        $form = $this->formWithFields([
            new FieldDefinition(
                id: 1,
                type: 'content',
                name: 'intro',
                label: 'Intro',
            ),
            new FieldDefinition(
                id: 2,
                type: 'text',
                name: 'name',
                label: 'Name',
            ),
        ]);

        $result = (new TokenReplacer())->replace('{entry:fields}', $form, ['name' => 'Ann']);

        static::assertStringNotContainsString('Intro', $result);
    }

    #[Test]
    public function entryFieldsSkipsHiddenFields(): void
    {
        $replacer = new TokenReplacer();
        $form     = $this->formWithFields([
            new FieldDefinition(
                id: 1,
                type: 'text',
                name: 'name',
                label: 'Name',
            ),
            new FieldDefinition(
                id: 2,
                type: 'hidden',
                name: 'utm_source',
                label: 'UTM source',
            ),
        ]);

        $result = $replacer->replace('{entry:fields}', $form, [
            'name'       => 'Alice',
            'utm_source' => 'newsletter',
        ]);

        static::assertStringContainsString('Name', $result);
        static::assertStringNotContainsString('UTM source', $result);
        static::assertStringNotContainsString('newsletter', $result);
    }

    #[Test]
    public function flattensArrayFieldValues(): void
    {
        $replacer = new TokenReplacer();
        $form     = new FormDefinition(
            id: 1,
            slug: 'x',
            title: 'X',
        );

        $result = $replacer->replace(
            'Tags: {field:tags}',
            $form,
            ['tags' => ['php', 'forms', 'cms']],
            [],
        );

        static::assertSame('Tags: php, forms, cms', $result);
    }

    #[Test]
    public function leavesUnknownTokensIntactToSurfaceTypos(): void
    {
        $replacer = new TokenReplacer();
        $form     = new FormDefinition(
            id: 1,
            slug: 'x',
            title: 'X',
        );

        $result = $replacer->replace('Hello {field:missing} {form:nope}', $form, [], []);

        static::assertSame('Hello {field:missing} {form:nope}', $result);
    }

    #[Test]
    public function namespaceMatchingIsCaseInsensitive(): void
    {
        $result = (new TokenReplacer())->replace('{FIELD:x}', $this->formWithFields([]), ['x' => 'y']);

        static::assertSame('y', $result);
    }

    #[Test]
    public function nonScalarFieldValueResolvesEmpty(): void
    {
        $result = (new TokenReplacer())->replace('[{field:x}]', $this->formWithFields([]), ['x' => new stdClass()]);

        static::assertSame('[]', $result);
    }

    #[Test]
    public function replaceForHtmlDoesNotDoubleEscapeTheFieldsTable(): void
    {
        $form = $this->formWithFields([
            new FieldDefinition(
                id: 1,
                type: 'text',
                name: 'name',
                label: 'Name',
            ),
        ]);

        $result = (new TokenReplacer())->replaceForHtml('{entry:fields}', $form, ['name' => '<b>Bob</b>']);

        static::assertSame((new TokenReplacer())->replace('{entry:fields}', $form, ['name' => '<b>Bob</b>']), $result);
        static::assertStringContainsString('&lt;b&gt;Bob&lt;/b&gt;', $result);
        static::assertStringNotContainsString('&amp;lt;', $result);
    }

    #[Test]
    public function replaceForHtmlEscapesCustomNamespaceValues(): void
    {
        $replacer = new TokenReplacer();
        $replacer->register('crm', static fn(string $key): string => "<{$key}>");

        static::assertSame('&lt;owner&gt;', $replacer->replaceForHtml('{crm:owner}', $this->formWithFields([]), []));
    }

    #[Test]
    public function replaceForHtmlEscapesFormAndSiteValues(): void
    {
        $replacer = new TokenReplacer(['base_url' => 'https://example.com/?a=1&b=2']);
        $form     = new FormDefinition(
            id: 1,
            slug: 'contact',
            title: 'Q&A <draft>',
        );

        $result = $replacer->replaceForHtml('{form:title} {site:base_url}', $form, []);

        static::assertSame('Q&amp;A &lt;draft&gt; https://example.com/?a=1&amp;b=2', $result);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $entry
     */
    #[Test]
    #[DataProvider('htmlEscapingProvider')]
    public function replaceForHtmlEscapesResolvedValues(
        string $template,
        array $values,
        array $entry,
        string $expected,
    ): void {
        $result = (new TokenReplacer())->replaceForHtml($template, $this->formWithFields([]), $values, $entry);

        static::assertSame($expected, $result);
    }

    #[Test]
    public function replaceForHtmlMatchesTheFieldsTableTokenCaseInsensitively(): void
    {
        $form = $this->formWithFields([
            new FieldDefinition(
                id: 1,
                type: 'text',
                name: 'name',
                label: 'Name',
            ),
        ]);

        $result = (new TokenReplacer())->replaceForHtml('{ENTRY:fields}', $form, ['name' => 'Bob']);

        static::assertStringStartsWith('<table', $result);
    }

    #[Test]
    public function replaceForUrlEncodesPathTraversalAttempts(): void
    {
        $replacer = new TokenReplacer();
        $form     = new FormDefinition(
            id: 1,
            slug: 'x',
            title: 'X',
        );

        $result = $replacer->replaceForUrl(
            '/profile/{field:slug}',
            $form,
            ['slug' => '../admin'],
            [],
        );

        // `/` in the substituted value gets encoded as `%2F` — prevents a
        // submitted value from escaping the URL path component.
        static::assertSame('/profile/..%2Fadmin', $result);
    }

    #[Test]
    public function replaceForUrlEncodesResolvedValues(): void
    {
        $replacer = new TokenReplacer();
        $form     = new FormDefinition(
            id: 1,
            slug: 'contact',
            title: 'Contact',
        );

        $result = $replacer->replaceForUrl(
            'https://example.com/thanks?email={field:email}&name={field:name}',
            $form,
            ['email' => 'a@b.co', 'name' => 'Alice & Bob'],
            [],
        );

        // `@` in `a@b.co` becomes `%40`; ampersand and space in `Alice & Bob`
        // become `%20%26%20` — without this encoding the ampersand would
        // close the `name=` query parameter and inject a new one.
        static::assertSame(
            'https://example.com/thanks?email=a%40b.co&name=Alice%20%26%20Bob',
            $result,
        );
    }

    #[Test]
    public function replaceForUrlLeavesUnresolvedTokensIntact(): void
    {
        $replacer = new TokenReplacer();
        $form     = new FormDefinition(
            id: 1,
            slug: 'x',
            title: 'X',
        );

        // {field:missing} can't resolve — falls through; {form:title} resolves
        // to "X" (no encoding needed since it's already URL-safe).
        $result = $replacer->replaceForUrl(
            'https://x.test/?missing={field:missing}&title={form:title}',
            $form,
            [],
            [],
        );

        static::assertSame('https://x.test/?missing={field:missing}&title=X', $result);
    }

    #[Test]
    public function replaceForUrlStillEncodesTheFieldsTable(): void
    {
        $form = $this->formWithFields([
            new FieldDefinition(
                id: 1,
                type: 'text',
                name: 'name',
                label: 'Name',
            ),
        ]);

        $result = (new TokenReplacer())->replaceForUrl('{entry:fields}', $form, ['name' => 'Bob']);

        static::assertStringStartsWith('%3Ctable', $result);
    }

    #[Test]
    public function replacesFieldFormAndEntryTokens(): void
    {
        $replacer = new TokenReplacer();
        $form     = new FormDefinition(
            id: 1,
            slug: 'contact',
            title: 'Contact us',
            description: 'Get in touch',
        );

        $result = $replacer->replace(
            'Hi {field:name}, your {form:title} entry #{entry:id} on {entry:date}',
            $form,
            ['name' => 'Alice'],
            ['id' => 42, 'date' => '2026-05-08'],
        );

        static::assertSame('Hi Alice, your Contact us entry #42 on 2026-05-08', $result);
    }

    #[Test]
    #[DataProvider('formTokenProvider')]
    public function resolvesFormAttributes(string $template, string $expected): void
    {
        static::assertSame($expected, (new TokenReplacer())->replace($template, $this->formWithFields([]), []));
    }

    #[Test]
    #[DataProvider('entryValueProvider')]
    public function resolvesScalarEntryAttributesOnly(mixed $id, string $expected): void
    {
        $result = (new TokenReplacer())->replace('Entry {entry:id}', $this->formWithFields([]), [], ['id' => $id]);

        static::assertSame($expected, $result);
    }

    #[Test]
    public function siteContextWithMissingKeyPreservesToken(): void
    {
        $replacer = new TokenReplacer(['admin_url' => 'https://admin.example']);
        $form     = new FormDefinition(
            id: 1,
            slug: 'x',
            title: 'X',
        );

        $result = $replacer->replace('Visit {site:not_set}', $form, [], []);

        static::assertSame('Visit {site:not_set}', $result);
    }

    #[Test]
    public function siteContextWithNullValueRendersEmpty(): void
    {
        $replacer = new TokenReplacer(['telephone' => null]);
        $form     = new FormDefinition(
            id: 1,
            slug: 'x',
            title: 'X',
        );

        $result = $replacer->replace('Call {site:telephone} today', $form, [], []);

        static::assertSame('Call  today', $result);
    }

    private function formWithFields(array $fields): FormDefinition
    {
        return new FormDefinition(
            id: 1,
            slug: 'contact',
            title: 'Contact',
            sections: [
                new SectionDefinition(
                    id: 1,
                    key: 'main',
                    groups: [
                        new GroupDefinition(
                            id: 1,
                            rows: [
                                new RowDefinition(
                                    id: 1,
                                    fields: $fields,
                                ),
                            ],
                        ),
                    ],
                ),
            ],
        );
    }
}
