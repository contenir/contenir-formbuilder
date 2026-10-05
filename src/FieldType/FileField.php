<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\FieldType;

use Contenir\FormBuilder\Definition\FieldDefinition;
use Laminas\Form\Element\File;
use Laminas\Form\ElementInterface;
use Override;

use function is_string;

/**
 * @api
 */
class FileField extends AbstractFieldType
{
    #[Override]
    public function icon(): string
    {
        return 'paperclip';
    }

    #[Override]
    public function key(): string
    {
        return 'file';
    }

    #[Override]
    public function label(): string
    {
        return 'File upload';
    }

    #[Override]
    public function supportedGroups(): array
    {
        return ['label', 'visibility', 'description', 'required', 'options', 'conditional'];
    }

    /**
     * @mago-expect analysis:mixed-assignment Field options are decoded JSON; values are type-checked before use.
     */
    #[Override]
    protected function applyHtml5Hints(ElementInterface $element, FieldDefinition $field): void
    {
        $accept = $field->options['accept'] ?? null;
        if (is_string($accept) && '' !== $accept) {
            $element->setAttribute('accept', $accept);
        }
    }

    #[Override]
    protected function createElement(FieldDefinition $field): ElementInterface
    {
        return new File($field->name);
    }
}
