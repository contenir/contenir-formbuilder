<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\FieldType;

use Contenir\FormBuilder\Definition\FieldDefinition;
use Laminas\Form\Element\Hidden;
use Laminas\Form\ElementInterface;
use Override;

/**
 * Static content block — instructional text / HTML rendered inline in
 * the form, not collected as user data.
 *
 * The body is stored on `field->options['html']` and runs through
 * an HTML sanitizer on save (controller) and
 * on render (FormMarkup). FormBuilderService skips this type when
 * assembling Laminas inputs because there's nothing to submit; the
 * createElement implementation returns a Hidden element so the type
 * still satisfies the buildElement contract for any caller that
 * doesn't first check {@see isStatic()}.
 *
 * @api
 */
final class ContentField extends AbstractFieldType
{
    #[Override]
    public function icon(): string
    {
        return 'note';
    }

    #[Override]
    public function isStatic(): bool
    {
        return true;
    }

    #[Override]
    public function key(): string
    {
        return 'content';
    }

    #[Override]
    public function label(): string
    {
        return 'Content / instructions';
    }

    #[Override]
    public function supportedGroups(): array
    {
        return ['conditional'];
    }

    #[Override]
    public function supportedValidators(): array
    {
        return [];
    }

    #[Override]
    protected function createElement(FieldDefinition $field): ElementInterface
    {
        return new Hidden($field->name);
    }
}
