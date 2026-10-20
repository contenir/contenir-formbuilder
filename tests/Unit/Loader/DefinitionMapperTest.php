<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Loader;

use Contenir\FormBuilder\Definition\FieldDefinition;
use Contenir\FormBuilder\Definition\NotificationDefinition;
use Contenir\FormBuilder\Definition\ValidatorDefinition;
use Contenir\FormBuilder\Definition\WebhookDefinition;
use Contenir\FormBuilder\Loader\DefinitionMapper;
use Contenir\FormBuilder\Persistence\Row;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class DefinitionMapperTest extends TestCase
{
    #[Test]
    public function readsAFieldMissingItsOptionalColumnsAsTheInstallersDefaults(): void
    {
        static::assertEquals(
            new FieldDefinition(
                id: 3,
                type: 'text',
                name: 'name',
            ),
            DefinitionMapper::field(new Row(['form_field_id' => 3, 'type' => 'text', 'name' => 'name'])),
        );
    }

    #[Test]
    public function readsANotificationMissingItsOptionalColumnsAsTheInstallersDefaults(): void
    {
        static::assertEquals(
            new NotificationDefinition(
                id: 4,
                name: 'Staff',
            ),
            DefinitionMapper::notification(new Row(['form_notification_id' => 4, 'name' => 'Staff'])),
        );
    }

    #[Test]
    public function readsAWebhookMissingItsOptionalColumnsAsTheInstallersDefaults(): void
    {
        static::assertEquals(
            new WebhookDefinition(
                id: 5,
                name: 'CRM',
                url: 'https://example.com',
            ),
            DefinitionMapper::webhook(new Row([
                'form_webhook_id' => 5,
                'name'            => 'CRM',
                'url'             => 'https://example.com',
            ])),
        );
    }

    #[Test]
    public function skipsValidatorsAndFiltersItCannotReadWhereverTheyAre(): void
    {
        $field = DefinitionMapper::field(new Row([
            'form_field_id'   => 3,
            'type'            => 'text',
            'name'            => 'name',
            'validators_json' => '["junk",{"options":{}},{"type":"not_empty"},{"type":null},{"type":"email_address"}]',
            'filters_json'    => '["",7,"StringTrim",null,"StripTags"]',
        ]));

        static::assertEquals(
            [
                [new ValidatorDefinition('not_empty'), new ValidatorDefinition('email_address')],
                ['StringTrim', 'StripTags'],
            ],
            [$field->validators, $field->filters],
        );
    }
}
