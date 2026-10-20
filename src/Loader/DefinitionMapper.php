<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Loader;

use Contenir\FormBuilder\Definition\FieldDefinition;
use Contenir\FormBuilder\Definition\NotificationDefinition;
use Contenir\FormBuilder\Definition\ValidatorDefinition;
use Contenir\FormBuilder\Definition\WebhookDefinition;
use Contenir\FormBuilder\Persistence\PersistenceException;
use Contenir\FormBuilder\Persistence\Row;

use function array_filter;
use function is_array;
use function is_string;
use function max;
use function min;
use function strtoupper;

/**
 * Turns rows of form_field, form_notification and form_webhook into their
 * definitions, reading JSON columns leniently: a column that is empty or not
 * JSON reads as no options, validators, filters, conditions or headers.
 *
 * @internal
 */
final class DefinitionMapper
{
    /**
     * @throws PersistenceException
     *
     * @mago-expect analysis:mixed-assignment Decoded JSON is untyped; each validator is checked with is_array().
     */
    public static function field(Row $row): FieldDefinition
    {
        $span       = $row->nullableInt('col_span');
        $validators = [];
        foreach ($row->jsonList('validators_json') as $validator) {
            if (! is_array($validator) || null === ($validator['type'] ?? null)) {
                continue;
            }
            $validators[] = ValidatorDefinition::fromArray($validator);
        }

        return new FieldDefinition(
            id: $row->int('form_field_id'),
            type: $row->string('type'),
            name: $row->string('name'),
            label: $row->nullableString('label'),
            showLabel: $row->bool('show_label', default: true),
            description: $row->nullableString('description'),
            placeholder: $row->nullableString('placeholder'),
            defaultValue: $row->nullableString('default_value'),
            required: $row->bool('required', default: false),
            colSpan: null === $span ? 4 : max(1, min(4, $span)),
            sort: $row->int('sort'),
            options: $row->jsonMap('options_json'),
            validators: $validators,
            filters: self::filters($row),
            conditional: $row->nullableJsonMap('conditional_json'),
        );
    }

    /**
     * @throws PersistenceException
     */
    public static function notification(Row $row): NotificationDefinition
    {
        return new NotificationDefinition(
            id: $row->int('form_notification_id'),
            name: $row->string('name'),
            trigger: $row->string('trigger', default: 'submit'),
            toAddress: $row->string('to_address'),
            fromAddress: $row->nullableString('from_address'),
            replyTo: $row->nullableString('reply_to'),
            subject: $row->string('subject'),
            bodyTemplate: $row->nullableString('body_template'),
            conditions: $row->nullableJsonMap('conditions_json'),
            enabled: $row->bool('enabled', default: true),
            sort: $row->int('sort'),
        );
    }

    /**
     * @throws PersistenceException
     */
    public static function webhook(Row $row): WebhookDefinition
    {
        $secret = $row->nullableString('secret');

        return new WebhookDefinition(
            id: $row->int('form_webhook_id'),
            name: $row->string('name'),
            url: $row->string('url'),
            method: strtoupper($row->string('method', default: 'POST')),
            secret: '' === $secret ? null : $secret,
            headers: array_filter($row->jsonMap('headers_json'), is_string(...)),
            enabled: $row->bool('enabled', default: true),
            sort: $row->int('sort'),
        );
    }

    /**
     * The names of a field's filters: the non-empty strings in its JSON list.
     *
     * @return list<string>
     *
     * @mago-expect analysis:mixed-assignment Decoded JSON is untyped; each filter is checked with is_string().
     */
    private static function filters(Row $row): array
    {
        $filters = [];
        foreach ($row->jsonList('filters_json') as $filter) {
            if (! is_string($filter) || '' === $filter) {
                continue;
            }
            $filters[] = $filter;
        }

        return $filters;
    }
}
