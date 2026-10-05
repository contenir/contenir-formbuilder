<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\FieldType;

use OutOfBoundsException;

use function array_filter;
use function array_key_exists;
use function array_values;
use function sprintf;

/**
 * Lookup table of available field types, keyed by {@see FieldTypeInterface::key()}.
 *
 * The default constructor wires up the built-in catalogue. Additional types
 * can be registered programmatically via {@see register()} — that hook is
 * what lets future modules (or tests) extend the type vocabulary without
 * editing this class.
 *
 * @throws \OutOfBoundsException From {@see get()} when the requested key is unknown.
 *
 * @api
 */
final class FieldTypeRegistry
{
    /** @var array<string, FieldTypeInterface> */
    private array $types = [];

    /** @param iterable<FieldTypeInterface>|null $extras */
    public function __construct(?iterable $extras = null)
    {
        foreach (self::defaultTypes() as $type) {
            $this->register($type);
        }

        if (null !== $extras) {
            foreach ($extras as $extra) {
                $this->register($extra);
            }
        }
    }

    /** @return list<FieldTypeInterface> */
    public static function defaultTypes(): array
    {
        return [
            new TextField(),
            new TextareaField(),
            new EmailField(),
            new UrlField(),
            new TelField(),
            new NumberField(),
            new DateField(),
            new DateTimeField(),
            new TimeField(),
            new SelectField(),
            new MultiselectField(),
            new RadioField(),
            new CheckboxField(),
            new MulticheckboxField(),
            new FileField(),
            new HiddenField(),
            new ContentField(),
        ];
    }

    /** @return list<FieldTypeInterface> */
    public function all(): array
    {
        return array_values($this->types);
    }

    /**
     * @throws \OutOfBoundsException If $key is not registered.
     */
    public function get(string $key): FieldTypeInterface
    {
        if (! array_key_exists($key, $this->types)) {
            throw new OutOfBoundsException(sprintf('Unknown field type "%s"', $key));
        }
        return $this->types[$key];
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->types);
    }

    public function register(FieldTypeInterface $type): void
    {
        $this->types[$type->key()] = $type;
    }

    /** @return list<FieldTypeInterface> */
    public function userSelectable(): array
    {
        return array_values(array_filter(
            $this->types,
            static fn(FieldTypeInterface $type): bool => $type->isUserSelectable(),
        ));
    }
}
