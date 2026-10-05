<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\FieldType;

use Contenir\FormBuilder\Definition\FieldDefinition;
use Laminas\Form\Element\Textarea;
use Laminas\Form\ElementInterface;
use Override;

use function is_numeric;

/**
 * @api
 */
class TextareaField extends AbstractFieldType
{
    #[Override]
    public function icon(): string
    {
        return 'list';
    }

    #[Override]
    public function key(): string
    {
        return 'textarea';
    }

    #[Override]
    public function label(): string
    {
        return 'Multi-line text';
    }

    /**
     * @mago-expect analysis:mixed-assignment Field options are decoded JSON; `rows` is checked before use.
     */
    #[Override]
    protected function createElement(FieldDefinition $field): ElementInterface
    {
        $element = new Textarea($field->name);
        $rows    = $field->options['rows'] ?? null;
        $element->setAttribute('rows', (string) (is_numeric($rows) ? (int) $rows : 5));
        return $element;
    }
}
