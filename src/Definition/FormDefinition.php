<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Definition;

/**
 * Top-level form aggregate consumed by the Builder service and admin UI.
 *
 * Loaders produce {@see FormDefinition} from any source (DB today, programmatic
 * arrays in tests); the Builder service consumes only this shape so the form
 * engine has a single, framework-agnostic input contract.
 *
 * Layout modes:
 *  - `single`  — render every section sequentially in one page (v1).
 *  - `stepped` — wizard-style, one section at a time (v2 surface; schema-ready).
 *
 * @phpstan-type FormSettings array<string, mixed>
 *
 * @api
 */
final class FormDefinition
{
    public const string LAYOUT_SINGLE  = 'single';
    public const string LAYOUT_STEPPED = 'stepped';

    public const string STATUS_ACTIVE   = 'active';
    public const string STATUS_INACTIVE = 'inactive';

    /**
     * Post-submission action modes. Stored under `settings.success.mode`;
     * each value maps to a branch in `Forms_SubmitController`.
     */
    public const string SUCCESS_REDIRECT_REFERRER = 'redirect_referrer';
    public const string SUCCESS_REDIRECT_URL      = 'redirect_url';
    public const string SUCCESS_INLINE_MESSAGE    = 'inline_message';

    public const array SUCCESS_MODES = [
        self::SUCCESS_REDIRECT_REFERRER,
        self::SUCCESS_REDIRECT_URL,
        self::SUCCESS_INLINE_MESSAGE,
    ];

    /**
     * @param list<SectionDefinition> $sections
     * @param list<NotificationDefinition> $notifications
     * @param list<WebhookDefinition> $webhooks
     * @param FormSettings $settings
     *
     * @mago-expect lint:excessive-parameter-list Immutable value object; the promoted constructor is its public shape and is called with named arguments.
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $slug,
        public readonly string $title,
        public readonly ?string $description = null,
        public readonly string $layoutMode = self::LAYOUT_SINGLE,
        public readonly string $submitLabel = 'Submit',
        public readonly string $submitAlignment = 'left',
        public readonly array $settings = [],
        public readonly ?int $retentionDays = null,
        public readonly string $status = self::STATUS_ACTIVE,
        public readonly array $sections = [],
        public readonly array $notifications = [],
        public readonly array $webhooks = [],
    ) {}

    /** @return list<FieldDefinition> */
    public function getAllFields(): array
    {
        $fields = [];
        foreach ($this->sections as $section) {
            foreach ($section->groups as $group) {
                foreach ($group->rows as $row) {
                    foreach ($row->fields as $field) {
                        $fields[] = $field;
                    }
                }
            }
        }

        return $fields;
    }
}
