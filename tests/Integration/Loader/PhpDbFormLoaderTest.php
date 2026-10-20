<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Integration\Loader;

use Closure;
use Contenir\FormBuilder\Definition\FieldDefinition;
use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Definition\GroupDefinition;
use Contenir\FormBuilder\Definition\NotificationDefinition;
use Contenir\FormBuilder\Definition\RowDefinition;
use Contenir\FormBuilder\Definition\SectionDefinition;
use Contenir\FormBuilder\Definition\ValidatorDefinition;
use Contenir\FormBuilder\Definition\WebhookDefinition;
use Contenir\FormBuilder\Loader\PhpDbFormLoader;
use Contenir\FormBuilder\Tests\TestAsset\Database\Seeder;
use Contenir\FormBuilder\Tests\Trait\TestDatabaseTrait;
use PhpDb\Adapter\Profiler\Profiler;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function bin2hex;
use function count;
use function random_bytes;

#[Group('integration')]
#[Group('repository')]
final class PhpDbFormLoaderTest extends TestCase
{
    use TestDatabaseTrait;

    private Seeder $seed;

    private PhpDbFormLoader $loader;

    #[Test]
    public function findsAFormBySlug(): void
    {
        $ids = $this->seed->form(['slug' => 'newsletter', 'title' => 'Newsletter']);

        static::assertSame(
            [$ids['form'], null],
            [$this->loader->loadBySlug('newsletter')?->id, $this->loader->loadBySlug('missing')],
        );
    }

    #[Test]
    public function keepsAFieldsWidthBetweenOneAndFour(): void
    {
        $ids = $this->seed->form();
        foreach ([0, 9] as $sort => $span) {
            $this->seed->insert('form_field', [
                'form_row_id' => $ids['row'],
                'type'        => 'text',
                'name'        => "f{$sort}",
                'col_span'    => $span,
                'sort'        => $sort,
            ]);
        }

        static::assertSame(
            [1, 4],
            array_map(
                static fn(FieldDefinition $field): int => $field->colSpan,
                $this->loader->loadById($ids['form'])?->getAllFields() ?? [],
            ),
        );
    }

    #[Test]
    public function listsEveryFormsSummaryByTitle(): void
    {
        $bravo = $this->seed->form(['slug' => 'b', 'title' => 'Bravo', 'status' => 'inactive']);
        $alpha = $this->seed->form(['slug' => 'a', 'title' => 'Alpha']);

        static::assertSame(
            [
                ['id' => $alpha['form'], 'slug' => 'a', 'title' => 'Alpha', 'status' => 'active'],
                ['id' => $bravo['form'], 'slug' => 'b', 'title' => 'Bravo', 'status' => 'inactive'],
            ],
            $this->loader->listSummaries(),
        );
    }

    #[Test]
    public function loadsAFormWithAnEmptyGroup(): void
    {
        $formId    = $this->seed->insert('form', ['slug' => 'empty', 'title' => 'Empty']);
        $sectionId = $this->seed->insert('form_section', ['form_id' => $formId, 'key' => 'main']);
        $this->seed->insert('form_group', ['form_section_id' => $sectionId]);

        static::assertSame([], $this->loader->loadById($formId)?->sections[0]->groups[0]->rows);
    }

    #[Test]
    public function loadsAFormWithAnEmptySection(): void
    {
        $formId = $this->seed->insert('form', ['slug' => 'empty', 'title' => 'Empty']);
        $this->seed->insert('form_section', ['form_id' => $formId, 'key' => 'main']);

        static::assertSame([], $this->loader->loadById($formId)?->sections[0]->groups);
    }

    #[Test]
    public function loadsAFormWithNoLayout(): void
    {
        $formId = $this->seed->insert('form', ['slug' => 'empty', 'title' => 'Empty']);

        static::assertSame([], $this->loader->loadById($formId)?->sections);
    }

    #[Test]
    public function loadsAWholeFormInSevenQueries(): void
    {
        $ids = $this->seed->form();
        $this->seed->insert('form_field', ['form_row_id' => $ids['row'], 'type' => 'text', 'name' => 'name']);

        static::assertSame(7, $this->queries(fn(): mixed => $this->loader->loadById($ids['form'])));
    }

    /**
     * @mago-expect lint:halstead One whole form, every column set, seeded and expected in full.
     */
    #[Test]
    public function loadsAWholeFormInSortOrder(): void
    {
        $formId = $this->seed->insert('form', [
            'slug'             => 'enquiry',
            'title'            => 'Enquiry',
            'description'      => 'Ask us',
            'layout_mode'      => 'stepped',
            'submit_label'     => 'Send',
            'submit_alignment' => 'right',
            'settings_json'    => '{"success_mode":"inline_message","0":"dropped"}',
            'retention_days'   => 365,
            'status'           => 'inactive',
        ]);
        $second = $this->seed->insert('form_section', ['form_id' => $formId, 'key' => 'second', 'sort' => 2]);
        $first  = $this->seed->insert('form_section', [
            'form_id'     => $formId,
            'key'         => 'first',
            'legend'      => 'About you',
            'description' => 'Who you are',
            'sort'        => 1,
        ]);
        $group = $this->seed->insert('form_group', [
            'form_section_id' => $first,
            'legend'          => 'Name',
            'description'     => 'As on your ID',
            'sort'            => 1,
        ]);
        $rowB  = $this->seed->insert('form_row', ['form_group_id' => $group, 'sort' => 2]);
        $rowA  = $this->seed->insert('form_row', ['form_group_id' => $group, 'sort' => 1]);
        $email = $this->seed->insert('form_field', [
            'form_row_id'      => $rowA,
            'type'             => 'email',
            'name'             => 'email',
            'label'            => 'Email',
            'show_label'       => 0,
            'description'      => 'We reply here',
            'placeholder'      => 'you@example.com',
            'default_value'    => 'x@example.com',
            'required'         => 1,
            'col_span'         => 2,
            'sort'             => 2,
            'options_json'     => '{"autocomplete":"email"}',
            'validators_json'  => '[{"type":"email_address","message":"Bad"},{"options":{}},"junk"]',
            'filters_json'     => '["StringTrim","",5]',
            'conditional_json' => '{"show_when":{"all":[]}}',
        ]);
        $name = $this->seed->insert('form_field', [
            'form_row_id' => $rowA,
            'type'        => 'text',
            'name'        => 'name',
            'sort'        => 1,
        ]);
        $later = $this->seed->insert('form_notification', [
            'form_id'    => $formId,
            'name'       => 'Later',
            'to_address' => 'b@example.com',
            'subject'    => 'B',
            'sort'       => 2,
        ]);
        $soon = $this->seed->insert('form_notification', [
            'form_id'         => $formId,
            'name'            => 'Soon',
            'trigger'         => 'submit',
            'to_address'      => 'a@example.com',
            'from_address'    => 'from@example.com',
            'reply_to'        => '{field:email}',
            'subject'         => 'A',
            'body_template'   => '<p>{entry:fields}</p>',
            'conditions_json' => '{"show_when":{"all":[{"field":"name","operator":"filled"}]}}',
            'enabled'         => 0,
            'sort'            => 1,
        ]);
        $secret = bin2hex(random_bytes(8));
        $hook   = $this->seed->insert('form_webhook', [
            'form_id'      => $formId,
            'name'         => 'CRM',
            'url'          => 'https://crm.example.com/hook',
            'method'       => 'put',
            'secret'       => $secret,
            'headers_json' => '{"X-Key":"abc","X-Bad":5}',
            'enabled'      => 0,
            'sort'         => 1,
        ]);

        static::assertEquals(
            new FormDefinition(
                id: $formId,
                slug: 'enquiry',
                title: 'Enquiry',
                description: 'Ask us',
                layoutMode: 'stepped',
                submitLabel: 'Send',
                submitAlignment: 'right',
                settings: ['success_mode' => 'inline_message'],
                retentionDays: 365,
                status: 'inactive',
                sections: [
                    new SectionDefinition(
                        id: $first,
                        key: 'first',
                        legend: 'About you',
                        description: 'Who you are',
                        sort: 1,
                        groups: [new GroupDefinition(
                            id: $group,
                            legend: 'Name',
                            description: 'As on your ID',
                            sort: 1,
                            rows: [
                                new RowDefinition(
                                    id: $rowA,
                                    sort: 1,
                                    fields: [
                                        new FieldDefinition(
                                            id: $name,
                                            type: 'text',
                                            name: 'name',
                                            sort: 1,
                                        ),
                                        new FieldDefinition(
                                            id: $email,
                                            type: 'email',
                                            name: 'email',
                                            label: 'Email',
                                            showLabel: false,
                                            description: 'We reply here',
                                            placeholder: 'you@example.com',
                                            defaultValue: 'x@example.com',
                                            required: true,
                                            colSpan: 2,
                                            sort: 2,
                                            options: ['autocomplete' => 'email'],
                                            validators: [new ValidatorDefinition('email_address', [], 'Bad')],
                                            filters: ['StringTrim'],
                                            conditional: ['show_when' => ['all' => []]],
                                        ),
                                    ],
                                ),
                                new RowDefinition(
                                    id: $rowB,
                                    sort: 2,
                                ),
                            ],
                        )],
                    ),
                    new SectionDefinition(
                        id: $second,
                        key: 'second',
                        sort: 2,
                    ),
                ],
                notifications: [
                    new NotificationDefinition(
                        id: $soon,
                        name: 'Soon',
                        trigger: 'submit',
                        toAddress: 'a@example.com',
                        fromAddress: 'from@example.com',
                        replyTo: '{field:email}',
                        subject: 'A',
                        bodyTemplate: '<p>{entry:fields}</p>',
                        conditions: ['show_when' => ['all' => [['field' => 'name', 'operator' => 'filled']]]],
                        enabled: false,
                        sort: 1,
                    ),
                    new NotificationDefinition(
                        id: $later,
                        name: 'Later',
                        toAddress: 'b@example.com',
                        subject: 'B',
                        sort: 2,
                    ),
                ],
                webhooks: [new WebhookDefinition(
                    id: $hook,
                    name: 'CRM',
                    url: 'https://crm.example.com/hook',
                    method: 'PUT',
                    secret: $secret,
                    headers: ['X-Key' => 'abc'],
                    enabled: false,
                    sort: 1,
                )],
            ),
            $this->loader->loadById($formId),
        );
    }

    #[Test]
    public function loadsEveryFormByTitle(): void
    {
        $this->seed->form(['slug' => 'b', 'title' => 'Bravo']);
        $this->seed->form(['slug' => 'a2', 'title' => 'Alpha']);
        $this->seed->form(['slug' => 'a1', 'title' => 'Alpha']);

        static::assertSame(
            ['a2', 'a1', 'b'],
            array_map(static fn(FormDefinition $form): string => $form->slug, $this->loader->loadAll()),
        );
    }

    #[Test]
    public function loadsOnlyTheFormAskedFor(): void
    {
        $mine   = $this->seed->form(['slug' => 'mine', 'title' => 'Mine']);
        $theirs = $this->seed->form(['slug' => 'theirs', 'title' => 'Theirs']);
        $this->seed->insert('form_field', ['form_row_id' => $theirs['row'], 'type' => 'text', 'name' => 'theirs']);
        $this->seed->insert('form_notification', [
            'form_id'    => $theirs['form'],
            'name'       => 'Theirs',
            'to_address' => 'a@example.com',
            'subject'    => 'S',
        ]);
        $this->seed->insert('form_webhook', ['form_id' => $theirs['form'], 'name' => 'Theirs', 'url' => 'https://x']);

        $form = $this->loader->loadById($mine['form']);

        static::assertSame([$theirs['form'], 1, [], [], []], [
            $this->loader->loadById($theirs['form'])?->id,
            count($form->sections ?? []),
            $form?->getAllFields(),
            $form?->notifications,
            $form?->webhooks,
        ]);
    }

    #[Test]
    public function readsAFormAsTheInstallerDefaultsLeftIt(): void
    {
        $ids = $this->seed->form();
        $this->seed->insert('form_field', ['form_row_id' => $ids['row'], 'type' => 'text', 'name' => 'name']);
        $this->seed->insert('form_webhook', [
            'form_id' => $ids['form'],
            'name'    => 'Hook',
            'url'     => 'https://example.com',
            'secret'  => '',
        ]);

        $form = $this->loader->loadById($ids['form']);

        static::assertEquals(
            [
                new FieldDefinition(
                    id: $form?->getAllFields()[0]->id,
                    type: 'text',
                    name: 'name',
                ),
                new WebhookDefinition(
                    id: $form?->webhooks[0]->id,
                    name: 'Hook',
                    url: 'https://example.com',
                ),
                ['single', 'Submit', 'left', 'active', null, []],
            ],
            [
                $form?->getAllFields()[0],
                $form?->webhooks[0],
                [
                    $form?->layoutMode,
                    $form?->submitLabel,
                    $form?->submitAlignment,
                    $form?->status,
                    $form?->retentionDays,
                    $form?->settings,
                ],
            ],
        );
    }

    #[Test]
    public function returnsNothingForAnUnknownId(): void
    {
        static::assertNull($this->loader->loadById(999));
    }

    #[Test]
    public function stopsQueryingTheLayoutAtTheFirstEmptyLevel(): void
    {
        $formId = $this->seed->insert('form', ['slug' => 'empty', 'title' => 'Empty']);

        static::assertSame(4, $this->queries(fn(): mixed => $this->loader->loadById($formId)));
    }

    protected function setUp(): void
    {
        $this->setUpDatabase();
        $this->seed   = new Seeder($this->adapter);
        $this->loader = new PhpDbFormLoader($this->adapter);
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabase();
    }

    /**
     * How many statements $work sends to the database.
     *
     * @param Closure(): mixed $work
     */
    private function queries(Closure $work): int
    {
        $profiler = new Profiler();
        $this->adapter->setProfiler($profiler);
        $work();

        return count($profiler->getProfiles());
    }
}
