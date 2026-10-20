<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Integration\Repository;

use Contenir\FormBuilder\Persistence\PersistenceException;
use Contenir\FormBuilder\Repository\PhpDbFormRepository;
use Contenir\FormBuilder\Tests\TestAsset\Attempt;
use Contenir\FormBuilder\Tests\TestAsset\Clock\FrozenClock;
use Contenir\FormBuilder\Tests\TestAsset\Database\Seeder;
use Contenir\FormBuilder\Tests\Trait\TestDatabaseTrait;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_intersect_key;
use function array_values;
use function bin2hex;
use function count;
use function random_bytes;

#[Group('integration')]
#[Group('repository')]
final class PhpDbFormRepositoryTest extends TestCase
{
    use TestDatabaseTrait;

    private const array FORM_COLUMNS = [
        'slug',
        'title',
        'description',
        'layout_mode',
        'submit_label',
        'submit_alignment',
        'settings_json',
        'retention_days',
        'status',
        'created',
        'updated',
    ];

    private const array FIELD_COLUMNS = [
        'form_row_id',
        'type',
        'name',
        'label',
        'show_label',
        'description',
        'placeholder',
        'default_value',
        'required',
        'col_span',
        'sort',
        'options_json',
        'validators_json',
        'filters_json',
        'conditional_json',
    ];

    private Seeder $seed;

    private PhpDbFormRepository $forms;

    #[Test]
    public function aFailedChangeLeavesNothingBehind(): void
    {
        $formId = $this->seed->insert('form', ['slug' => 'contact', 'title' => 'Contact']);
        $this->seed->insert('form_section', ['form_id' => $formId, 'key' => 'section-1']);

        $failure = Attempt::of(fn(): int => $this->forms->insertSection($formId, 0, key: 'section-1'));

        static::assertSame([PersistenceException::class, [], []], [
            $failure,
            $this->seed->rows('form_group', ['form_group_id']),
            $this->seed->rows('form_row', ['form_row_id']),
        ]);
    }

    #[Test]
    public function aValueThatIsNotAScalarIsStoredAsNull(): void
    {
        $formId = $this->seed->insert('form', ['slug' => 'contact', 'title' => 'Contact']);

        $this->forms->updateForm($formId, ['description' => ['not', 'text']]);

        static::assertSame([['description' => null]], $this->seed->rows('form', ['description']));
    }

    #[Test]
    public function createsAFieldWithEveryColumn(): void
    {
        $ids = $this->seed->form();

        $this->forms->createField($ids['row'], [
            'type'          => 'select',
            'name'          => 'colour',
            'label'         => 'Colour',
            'show_label'    => 0,
            'description'   => 'Pick one',
            'placeholder'   => 'Choose',
            'default_value' => 'red',
            'required'      => 'yes',
            'col_span'      => 9,
            'sort'          => '3',
            'options'       => ['choices' => ['red' => 'Red']],
            'validators'    => [['type' => 'in_array']],
            'filters'       => ['StringTrim'],
            'conditional'   => null,
        ]);

        static::assertSame(
            [[
                'form_row_id'      => (string) $ids['row'],
                'type'             => 'select',
                'name'             => 'colour',
                'label'            => 'Colour',
                'show_label'       => '0',
                'description'      => 'Pick one',
                'placeholder'      => 'Choose',
                'default_value'    => 'red',
                'required'         => '1',
                'col_span'         => '4',
                'sort'             => '3',
                'options_json'     => '{"choices":{"red":"Red"}}',
                'validators_json'  => '[{"type":"in_array"}]',
                'filters_json'     => '["StringTrim"]',
                'conditional_json' => null,
            ]],
            $this->seed->rows('form_field', self::FIELD_COLUMNS),
        );
    }

    #[Test]
    public function createsAFieldWithTheInstallersDefaultsAfterTheLast(): void
    {
        $ids = $this->seed->form();
        $this->seed->insert('form_field', ['form_row_id' => $ids['row'], 'type' => 'text', 'name' => 'a', 'sort' => 4]);

        $this->forms->createField($ids['row'], ['type' => 'text', 'name' => 'b', 'col_span' => -1, 'options' => 'x']);

        static::assertSame(
            ['1', '0', '1', '5', null],
            [
                ...array_values(array_intersect_key(
                    $this->seed->rows('form_field', self::FIELD_COLUMNS)[1],
                    ['show_label' => 1, 'required' => 1, 'col_span' => 1, 'sort' => 1, 'options_json' => 1],
                )),
            ],
        );
    }

    #[Test]
    public function createsAFormWithEverySetting(): void
    {
        $id = $this->forms->createForm([
            'slug'             => 'enquiry',
            'title'            => 'Enquiry',
            'description'      => 'Ask',
            'layout_mode'      => 'stepped',
            'submit_label'     => 'Send',
            'submit_alignment' => 'right',
            'settings'         => ['success_url' => 'https://example.com/thanks'],
            'retention_days'   => 365,
            'status'           => 'inactive',
        ]);

        static::assertSame(
            [[
                'slug'             => 'enquiry',
                'title'            => 'Enquiry',
                'description'      => 'Ask',
                'layout_mode'      => 'stepped',
                'submit_label'     => 'Send',
                'submit_alignment' => 'right',
                'settings_json'    => '{"success_url":"https://example.com/thanks"}',
                'retention_days'   => '365',
                'status'           => 'inactive',
                'created'          => '2026-10-20 09:30:00',
                'updated'          => '2026-10-20 09:30:00',
            ]],
            $this->seed->rows('form', self::FORM_COLUMNS, ['form_id' => $id]),
        );
    }

    #[Test]
    public function createsAFormWithTheInstallersDefaults(): void
    {
        $id = $this->forms->createForm(['slug' => 'contact', 'title' => 'Contact']);

        static::assertSame(
            [[
                'slug'             => 'contact',
                'title'            => 'Contact',
                'description'      => null,
                'layout_mode'      => 'single',
                'submit_label'     => 'Submit',
                'submit_alignment' => 'left',
                'settings_json'    => null,
                'retention_days'   => null,
                'status'           => 'active',
                'created'          => '2026-10-20 09:30:00',
                'updated'          => '2026-10-20 09:30:00',
            ]],
            $this->seed->rows('form', self::FORM_COLUMNS, ['form_id' => $id]),
        );
    }

    #[Test]
    public function createsAndUpdatesAGroup(): void
    {
        $ids = $this->seed->form();

        $groupId = $this->forms->createGroup($ids['section'], ['legend' => 'Contact', 'description' => 'How']);
        $this->forms->createGroup($ids['section'], ['sort' => 9]);
        $this->forms->updateGroup($groupId, ['legend' => 'Reach you', 'form_section_id' => 99]);
        $this->forms->updateGroup($groupId, []);

        static::assertSame(
            [
                ['form_section_id' => (string) $ids['section'], 'legend' => null, 'description' => null, 'sort' => '1'],
                [
                    'form_section_id' => (string) $ids['section'],
                    'legend'          => 'Reach you',
                    'description'     => 'How',
                    'sort'            => '2',
                ],
                ['form_section_id' => (string) $ids['section'], 'legend' => null, 'description' => null, 'sort' => '9'],
            ],
            $this->seed->rows('form_group', ['form_section_id', 'legend', 'description', 'sort']),
        );
    }

    #[Test]
    public function createsRowsAfterTheLastOrWhereToldTo(): void
    {
        $ids = $this->seed->form();

        $this->forms->createRow($ids['group']);
        $this->forms->createRow($ids['group'], 7);

        static::assertSame(
            [['sort' => '1'], ['sort' => '2'], ['sort' => '7']],
            $this->seed->rows('form_row', ['sort']),
        );
    }

    #[Test]
    public function createsSectionsAfterTheLast(): void
    {
        $formId = $this->seed->insert('form', ['slug' => 'contact', 'title' => 'Contact']);

        $other = $this->seed->insert('form', ['slug' => 'other', 'title' => 'Other']);
        $this->seed->insert('form_section', ['form_id' => $other, 'key' => 'other', 'sort' => 50]);

        $this->forms->createSection($formId, ['key' => 'one', 'legend' => 'One', 'description' => 'First']);
        $this->forms->createSection($formId, ['key' => 'ten', 'sort' => 10]);
        $this->forms->createSection($formId, ['key' => 'eleven']);

        static::assertSame(
            [
                ['key' => 'one', 'legend' => 'One', 'description' => 'First', 'sort' => '1'],
                ['key' => 'ten', 'legend' => null, 'description' => null, 'sort' => '10'],
                ['key' => 'eleven', 'legend' => null, 'description' => null, 'sort' => '11'],
            ],
            $this->seed->rows('form_section', ['key', 'legend', 'description', 'sort'], ['form_id' => $formId]),
        );
    }

    #[Test]
    public function createsUpdatesAndDeletesANotification(): void
    {
        $formId  = $this->seed->insert('form', ['slug' => 'contact', 'title' => 'Contact']);
        $columns = [
            'form_id',
            'name',
            'trigger',
            'to_address',
            'from_address',
            'reply_to',
            'subject',
            'body_template',
            'conditions_json',
            'enabled',
            'sort',
        ];

        $id = $this->forms->createNotification($formId, [
            'name'          => 'Staff',
            'to_address'    => 'staff@example.com',
            'from_address'  => 'web@example.com',
            'reply_to'      => '{field:email}',
            'subject'       => 'New entry',
            'body_template' => '{entry:fields}',
            'conditions'    => ['show_when' => ['any' => []]],
            'enabled'       => false,
            'sort'          => '2',
            'form_id'       => 99,
        ]);
        $created = $this->seed->rows('form_notification', $columns);
        $this->forms->updateNotification($id, [
            'subject'    => 'Entry',
            'conditions' => null,
            'enabled'    => 1,
            'form_id'    => 99,
        ]);
        $this->forms->updateNotification($id, []);
        $updated = $this->seed->rows('form_notification', $columns);
        $this->forms->deleteNotification($id);

        static::assertSame(
            [
                [[
                    'form_id'         => (string) $formId,
                    'name'            => 'Staff',
                    'trigger'         => 'submit',
                    'to_address'      => 'staff@example.com',
                    'from_address'    => 'web@example.com',
                    'reply_to'        => '{field:email}',
                    'subject'         => 'New entry',
                    'body_template'   => '{entry:fields}',
                    'conditions_json' => '{"show_when":{"any":[]}}',
                    'enabled'         => '0',
                    'sort'            => '2',
                ]],
                [[
                    'form_id'         => (string) $formId,
                    'name'            => 'Staff',
                    'trigger'         => 'submit',
                    'to_address'      => 'staff@example.com',
                    'from_address'    => 'web@example.com',
                    'reply_to'        => '{field:email}',
                    'subject'         => 'Entry',
                    'body_template'   => '{entry:fields}',
                    'conditions_json' => null,
                    'enabled'         => '1',
                    'sort'            => '2',
                ]],
                [],
            ],
            [$created, $updated, $this->seed->rows('form_notification', $columns)],
        );
    }

    #[Test]
    public function createsUpdatesAndDeletesAWebhook(): void
    {
        $formId  = $this->seed->insert('form', ['slug' => 'contact', 'title' => 'Contact']);
        $secret  = bin2hex(random_bytes(8));
        $columns = ['form_id', 'name', 'url', 'method', 'secret', 'headers_json', 'enabled', 'sort'];

        $id = $this->forms->createWebhook($formId, [
            'name'    => 'CRM',
            'url'     => 'https://crm.example.com/hook',
            'method'  => 'PUT',
            'secret'  => $secret,
            'headers' => ['X-Key' => 'abc'],
            'enabled' => true,
            'sort'    => 3,
            'form_id' => 99,
        ]);
        $created = $this->seed->rows('form_webhook', $columns);
        $this->forms->updateWebhook($id, ['headers' => [], 'secret' => null, 'enabled' => 0, 'form_id' => 99]);
        $this->forms->updateWebhook($id, []);
        $updated = $this->seed->rows('form_webhook', $columns);
        $this->forms->deleteWebhook($id);

        static::assertSame(
            [
                [[
                    'form_id'      => (string) $formId,
                    'name'         => 'CRM',
                    'url'          => 'https://crm.example.com/hook',
                    'method'       => 'PUT',
                    'secret'       => $secret,
                    'headers_json' => '{"X-Key":"abc"}',
                    'enabled'      => '1',
                    'sort'         => '3',
                ]],
                [[
                    'form_id'      => (string) $formId,
                    'name'         => 'CRM',
                    'url'          => 'https://crm.example.com/hook',
                    'method'       => 'PUT',
                    'secret'       => null,
                    'headers_json' => null,
                    'enabled'      => '0',
                    'sort'         => '3',
                ]],
                [],
            ],
            [$created, $updated, $this->seed->rows('form_webhook', $columns)],
        );
    }

    #[Test]
    public function deletesAField(): void
    {
        $ids     = $this->seed->form();
        $fieldId = $this->seed->insert('form_field', ['form_row_id' => $ids['row'], 'type' => 'text', 'name' => 'a']);

        $this->forms->deleteField($fieldId);

        static::assertSame([], $this->seed->rows('form_field', ['form_field_id']));
    }

    #[Test]
    public function deletesAGroup(): void
    {
        $ids = $this->seed->form();

        $this->forms->deleteGroup($ids['group']);

        static::assertSame([[], []], [
            $this->seed->rows('form_group', ['form_group_id']),
            $this->seed->rows('form_row', ['form_row_id']),
        ]);
    }

    #[Test]
    public function deletesARow(): void
    {
        $ids = $this->seed->form();

        $this->forms->deleteRow($ids['row']);

        static::assertSame([], $this->seed->rows('form_row', ['form_row_id']));
    }

    #[Test]
    public function deletesASection(): void
    {
        $ids = $this->seed->form();

        $this->forms->deleteSection($ids['section']);

        static::assertSame([[], []], [
            $this->seed->rows('form_section', ['form_section_id']),
            $this->seed->rows('form_group', ['form_group_id']),
        ]);
    }

    #[Test]
    public function deletingAFormDeletesItsLayout(): void
    {
        $ids = $this->seed->form();
        $this->seed->insert('form_field', ['form_row_id' => $ids['row'], 'type' => 'text', 'name' => 'name']);
        $other = $this->seed->form(['slug' => 'other']);

        $this->forms->deleteForm($ids['form']);

        static::assertSame(
            [[['form_id' => (string) $other['form']]], [['form_row_id' => (string) $other['row']]], []],
            [
                $this->seed->rows('form', ['form_id']),
                $this->seed->rows('form_row', ['form_row_id']),
                $this->seed->rows('form_field', ['form_field_id']),
            ],
        );
    }

    #[Test]
    public function insertsAGroupWithARowAtThePositionGiven(): void
    {
        $ids = $this->seed->form();

        $groupId = $this->forms->insertGroup($ids['section'], 0, legend: 'First');
        $this->forms->insertGroup($ids['section'], -5, legend: '');

        static::assertSame(
            [
                [
                    ['legend' => null, 'sort' => '3'],
                    ['legend' => 'First', 'sort' => '2'],
                    ['legend' => null, 'sort' => '1'],
                ],
                1,
            ],
            [
                $this->seed->rows('form_group', ['legend', 'sort']),
                count($this->seed->rows('form_row', ['form_row_id'], ['form_group_id' => $groupId])),
            ],
        );
    }

    #[Test]
    public function insertsARowAtThePositionGiven(): void
    {
        $ids = $this->seed->form();

        $rowId = $this->forms->insertRow($ids['group'], 0);

        static::assertSame(
            [['form_row_id' => (string) $ids['row'], 'sort' => '2'], ['form_row_id' => (string) $rowId, 'sort' => '1']],
            $this->seed->rows('form_row', ['form_row_id', 'sort']),
        );
    }

    #[Test]
    public function insertsASectionWithAGroupAndARow(): void
    {
        $ids   = $this->seed->form();
        $other = $this->seed->insert('form', ['slug' => 'other', 'title' => 'Other']);
        $this->seed->insert('form_section', ['form_id' => $other, 'key' => 'section-9']);
        $this->seed->insert('form_section', ['form_id' => $ids['form'], 'key' => 'section-4', 'sort' => 2]);
        $this->seed->insert('form_section', ['form_id' => $ids['form'], 'key' => 'section-x', 'sort' => 3]);

        $sectionId = $this->forms->insertSection($ids['form'], 1, legend: '');
        $groups    = $this->seed->rows('form_group', ['form_group_id'], ['form_section_id' => $sectionId]);

        static::assertSame(
            [
                [
                    ['key' => 'main', 'legend' => null, 'sort' => '1'],
                    ['key' => 'section-4', 'legend' => null, 'sort' => '3'],
                    ['key' => 'section-x', 'legend' => null, 'sort' => '4'],
                    ['key' => 'section-5', 'legend' => null, 'sort' => '2'],
                ],
                1,
                1,
            ],
            [
                $this->seed->rows('form_section', ['key', 'legend', 'sort'], ['form_id' => $ids['form']]),
                count($groups),
                count($this->seed->rows(
                    'form_row',
                    ['form_row_id'],
                    ['form_group_id' => (int) $groups[0]['form_group_id']],
                )),
            ],
        );
    }

    #[Test]
    public function insertsASectionWithTheKeyAndLegendGiven(): void
    {
        $ids = $this->seed->form();

        $this->forms->insertSection($ids['form'], 0, legend: 'Intro', key: 'intro');
        $this->forms->insertSection($ids['form'], 99, key: '');

        static::assertSame(
            [
                ['key' => 'main', 'legend' => null, 'sort' => '2'],
                ['key' => 'intro', 'legend' => 'Intro', 'sort' => '1'],
                ['key' => 'section-1', 'legend' => null, 'sort' => '3'],
            ],
            $this->seed->rows('form_section', ['key', 'legend', 'sort']),
        );
    }

    #[Test]
    public function reordersFieldsAcrossRowsWithoutTouchingTheirWidth(): void
    {
        $ids   = $this->seed->form();
        $other = $this->seed->insert('form_row', ['form_group_id' => $ids['group'], 'sort' => 2]);
        $a     = $this->seed->insert('form_field', [
            'form_row_id' => $ids['row'],
            'type'        => 'text',
            'name'        => 'a',
            'col_span'    => 2,
        ]);
        $b = $this->seed->insert('form_field', ['form_row_id' => $ids['row'], 'type' => 'text', 'name' => 'b']);
        $c = $this->seed->insert('form_field', ['form_row_id' => $other, 'type' => 'text', 'name' => 'c']);

        $this->forms->reorderFields([$ids['row'] => [$c, $a], $other => [$b]]);

        static::assertSame(
            [
                ['form_row_id' => (string) $ids['row'], 'sort' => '2', 'col_span' => '2'],
                ['form_row_id' => (string) $other, 'sort' => '1', 'col_span' => '4'],
                ['form_row_id' => (string) $ids['row'], 'sort' => '1', 'col_span' => '4'],
            ],
            $this->seed->rows('form_field', ['form_row_id', 'sort', 'col_span']),
        );
    }

    #[Test]
    public function reordersRowsAcrossGroups(): void
    {
        $ids   = $this->seed->form();
        $other = $this->seed->insert('form_group', ['form_section_id' => $ids['section'], 'sort' => 2]);
        $moved = $this->seed->insert('form_row', ['form_group_id' => $other, 'sort' => 1]);

        $this->forms->reorderRows($ids['group'], [4 => $moved, 7 => $ids['row']]);

        static::assertSame(
            [
                ['form_group_id' => (string) $ids['group'], 'sort' => '2'],
                ['form_group_id' => (string) $ids['group'], 'sort' => '1'],
            ],
            $this->seed->rows('form_row', ['form_group_id', 'sort']),
        );
    }

    #[Test]
    public function spawnsAFieldNamedAfterItsTypeAtThePositionGiven(): void
    {
        $ids   = $this->seed->form();
        $other = $this->seed->form(['slug' => 'other']);
        $this->seed->insert('form_field', [
            'form_row_id' => $ids['row'],
            'type'        => 'text',
            'name'        => 'text_2',
            'sort'        => 1,
        ]);
        $this->seed->insert('form_field', [
            'form_row_id' => $ids['row'],
            'type'        => 'text',
            'name'        => 'text_x',
            'sort'        => 2,
        ]);
        $this->seed->insert('form_field', [
            'form_row_id' => $ids['row'],
            'type'        => 'text',
            'name'        => 'mytext_9',
            'sort'        => 3,
        ]);
        $this->seed->insert('form_field', ['form_row_id' => $other['row'], 'type' => 'text', 'name' => 'text_7']);

        $this->forms->spawnField($ids['row'], 'text', 1);

        static::assertSame(
            [
                ['name' => 'text_2', 'sort' => '1', 'show_label' => '1'],
                ['name' => 'text_x', 'sort' => '3', 'show_label' => '1'],
                ['name' => 'mytext_9', 'sort' => '4', 'show_label' => '1'],
                ['name' => 'text_3', 'sort' => '2', 'show_label' => '1'],
            ],
            $this->seed->rows('form_field', ['name', 'sort', 'show_label'], ['form_row_id' => $ids['row']]),
        );
    }

    #[Test]
    public function spawnsAFieldNamedAfterTheFieldsInItsOwnForm(): void
    {
        $first  = $this->seed->form();
        $second = $this->seed->form(['slug' => 'second']);
        $this->seed->insert('form_field', ['form_row_id' => $first['row'], 'type' => 'text', 'name' => 'text_5']);
        $this->seed->insert('form_field', ['form_row_id' => $second['row'], 'type' => 'text', 'name' => 'text_2']);

        $this->forms->spawnField($second['row'], 'text', 9);

        static::assertSame(
            [['name' => 'text_2', 'sort' => '1'], ['name' => 'text_3', 'sort' => '2']],
            $this->seed->rows('form_field', ['name', 'sort'], ['form_row_id' => $second['row']]),
        );
    }

    #[Test]
    public function spawnsARowForAFieldDroppedBetweenRows(): void
    {
        $ids     = $this->seed->form();
        $fieldId = $this->seed->insert('form_field', [
            'form_row_id' => $ids['row'],
            'type'        => 'text',
            'name'        => 'a',
            'sort'        => 3,
        ]);

        $rowId = $this->forms->spawnFieldRow($ids['group'], $fieldId, 0);

        static::assertSame(
            [
                [
                    ['form_row_id' => (string) $ids['row'], 'sort' => '2'],
                    ['form_row_id' => (string) $rowId, 'sort' => '1'],
                ],
                [['form_row_id' => (string) $rowId, 'sort' => '1']],
            ],
            [
                $this->seed->rows('form_row', ['form_row_id', 'sort']),
                $this->seed->rows('form_field', ['form_row_id', 'sort']),
            ],
        );
    }

    #[Test]
    public function spawnsTheFirstFieldOfAType(): void
    {
        $ids = $this->seed->form();

        $this->forms->spawnField($ids['row'], 'email', 0);

        static::assertSame([['name' => 'email_1', 'sort' => '1']], $this->seed->rows('form_field', ['name', 'sort']));
    }

    #[Test]
    public function tellsWhetherASlugIsTaken(): void
    {
        $id = $this->seed->insert('form', ['slug' => 'contact', 'title' => 'Contact']);

        static::assertSame(
            [true, false, false, true],
            [
                $this->forms->isSlugTaken('contact'),
                $this->forms->isSlugTaken('other'),
                $this->forms->isSlugTaken('contact', $id),
                $this->forms->isSlugTaken('contact', $id + 1),
            ],
        );
    }

    #[Test]
    public function updatesEveryColumnOfAForm(): void
    {
        $id = $this->seed->insert('form', ['slug' => 'contact', 'title' => 'Contact']);

        $this->forms->updateForm($id, [
            'slug'             => 'enquiry',
            'title'            => 'Enquiry',
            'description'      => 'Ask',
            'layout_mode'      => 'stepped',
            'submit_label'     => 'Send',
            'submit_alignment' => 'center',
            'settings'         => ['a' => 2],
            'retention_days'   => 90,
            'status'           => 'inactive',
        ]);

        static::assertSame(
            [[
                'slug'             => 'enquiry',
                'title'            => 'Enquiry',
                'description'      => 'Ask',
                'layout_mode'      => 'stepped',
                'submit_label'     => 'Send',
                'submit_alignment' => 'center',
                'settings_json'    => '{"a":2}',
                'retention_days'   => '90',
                'status'           => 'inactive',
                'created'          => null,
                'updated'          => '2026-10-20 09:30:00',
            ]],
            $this->seed->rows('form', self::FORM_COLUMNS, ['form_id' => $id]),
        );
    }

    #[Test]
    public function updatesOnlyTheFieldColumnsGiven(): void
    {
        $ids     = $this->seed->form();
        $fieldId = $this->seed->insert('form_field', [
            'form_row_id'     => $ids['row'],
            'type'            => 'text',
            'name'            => 'name',
            'label'           => 'Name',
            'validators_json' => '[{"type":"not_empty"}]',
        ]);

        $this->forms->updateField($fieldId, [
            'type'        => 'textarea',
            'name'        => 'full_name',
            'sort'        => ['not', 'a', 'number'],
            'required'    => true,
            'validators'  => null,
            'conditional' => ['show_when' => ['all' => []]],
            'form_row_id' => 99,
        ]);
        $this->forms->updateField($fieldId, []);
        $this->forms->updateField($fieldId + 1, ['name' => 'nobody']);

        static::assertSame(
            [[
                'form_row_id'      => (string) $ids['row'],
                'type'             => 'textarea',
                'name'             => 'full_name',
                'label'            => 'Name',
                'show_label'       => '1',
                'description'      => null,
                'placeholder'      => null,
                'default_value'    => null,
                'required'         => '1',
                'col_span'         => '4',
                'sort'             => '0',
                'options_json'     => null,
                'validators_json'  => null,
                'filters_json'     => null,
                'conditional_json' => '{"show_when":{"all":[]}}',
            ]],
            $this->seed->rows('form_field', self::FIELD_COLUMNS),
        );
    }

    #[Test]
    public function updatesOnlyTheKeysGivenAndTheTime(): void
    {
        $id = $this->seed->insert('form', [
            'slug'           => 'contact',
            'title'          => 'Contact',
            'description'    => 'Old',
            'retention_days' => 30,
            'settings_json'  => '{"a":1}',
            'created'        => '2026-01-01 00:00:00',
        ]);

        $this->forms->updateForm($id, [
            'title'          => 'Get in touch',
            'slug'           => null,
            'settings'       => [],
            'description'    => null,
            'retention_days' => null,
        ]);

        static::assertSame(
            [[
                'slug'             => 'contact',
                'title'            => 'Get in touch',
                'description'      => null,
                'layout_mode'      => 'single',
                'submit_label'     => 'Submit',
                'submit_alignment' => 'left',
                'settings_json'    => '{"a":1}',
                'retention_days'   => null,
                'status'           => 'active',
                'created'          => '2026-01-01 00:00:00',
                'updated'          => '2026-10-20 09:30:00',
            ]],
            $this->seed->rows('form', self::FORM_COLUMNS, ['form_id' => $id]),
        );
    }

    #[Test]
    public function updatesOnlyTheSectionColumnsGiven(): void
    {
        $ids = $this->seed->form();

        $this->forms->updateSection($ids['section'], ['legend' => 'About', 'form_id' => 99, 'nonsense' => 1]);
        $this->forms->updateSection($ids['section'], []);

        static::assertSame(
            [['form_id' => (string) $ids['form'], 'key' => 'main', 'legend' => 'About', 'sort' => '1']],
            $this->seed->rows('form_section', ['form_id', 'key', 'legend', 'sort']),
        );
    }

    protected function setUp(): void
    {
        $this->setUpDatabase();
        $this->seed  = new Seeder($this->adapter);
        $this->forms = new PhpDbFormRepository(
            $this->adapter,
            new FrozenClock(new DateTimeImmutable('2026-10-20 09:30:00')),
        );
    }

    protected function tearDown(): void
    {
        $this->tearDownDatabase();
    }
}
