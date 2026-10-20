<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Registrar;

use ArrayObject;
use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Service\BuilderForm;
use SplSubject;

use function is_array;

/**
 * A submission as the registrars read it from a {@see BuilderForm}'s
 * registry: the form, the submitted values, the entry attributes so far,
 * the context, and whether it was caught as spam. A value of the wrong type
 * reads as empty.
 *
 * @internal
 */
final readonly class Submission
{
    /**
     * @param ArrayObject<string, mixed> $registry
     * @param array<string, mixed> $values
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $context
     *
     * @mago-expect lint:excessive-parameter-list Immutable value object; the promoted constructor is its shape.
     */
    private function __construct(
        public ArrayObject $registry,
        public FormDefinition $form,
        public array $values,
        public array $entry,
        public array $context,
        public bool $spam,
    ) {}

    /**
     * An array keyed by name, as strings; anything but an array is empty.
     *
     * @return array<string, mixed>
     *
     * @mago-expect analysis:mixed-assignment Registry values are untyped; they are passed on as they are.
     */
    public static function named(mixed $values): array
    {
        $named = [];
        foreach (is_array($values) ? $values : [] as $name => $value) {
            $named[(string) $name] = $value;
        }

        return $named;
    }

    /**
     * The submission $subject carries, or null when it is not a builder form
     * with a form definition in its registry.
     *
     * @mago-expect analysis:mixed-assignment The registry is an untyped bag; each value is checked here.
     */
    public static function of(SplSubject $subject): ?self
    {
        $registry = $subject instanceof BuilderForm ? $subject->registry : null;
        $form     = $registry['form'] ?? null;
        if (null === $registry || ! $form instanceof FormDefinition) {
            return null;
        }

        return new self(
            $registry,
            $form,
            self::named($registry['values'] ?? null),
            self::named($registry['entry'] ?? null),
            self::named($registry['context'] ?? null),
            true === ($registry['spam'] ?? false),
        );
    }
}
