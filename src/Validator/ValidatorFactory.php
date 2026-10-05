<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Validator;

use Contenir\FormBuilder\Definition\ValidatorDefinition;
use InvalidArgumentException;
use Laminas\Validator\Between;
use Laminas\Validator\Callback;
use Laminas\Validator\EmailAddress;
use Laminas\Validator\Regex;
use Laminas\Validator\StringLength;
use Laminas\Validator\ValidatorInterface;

use function filter_var;
use function is_numeric;
use function is_string;
use function preg_match;
use function sprintf;

use const FILTER_VALIDATE_BOOLEAN;
use const FILTER_VALIDATE_URL;
use const PHP_INT_MAX;

/**
 * Maps the curated UI validator vocabulary onto Laminas validator instances.
 *
 * Keeping the user-facing keys decoupled from concrete classes guards the
 * builder UI against breaking changes in the validator library and lets the
 * vocabulary stay small. Unknown keys throw — the builder never persists a
 * validator type the registry can't resolve, so this is a defensive guard
 * against hand-edited rows.
 *
 * @throws InvalidArgumentException From {@see create()} when the type is unknown.
 *
 * @api
 */
final class ValidatorFactory
{
    public const string TYPE_REQUIRED      = 'required';
    public const string TYPE_STRING_LENGTH = 'string_length';
    public const string TYPE_BETWEEN       = 'between';
    public const string TYPE_EMAIL         = 'email';
    public const string TYPE_URL           = 'url';
    public const string TYPE_REGEX         = 'regex';
    public const string TYPE_CONFIRM       = 'confirm';

    /**
     * The UI vocabulary of validator types — the field-edit form lists each
     * one as a checkbox plus its options inputs. {@see TYPE_REQUIRED} is
     * deliberately omitted because the field-edit form has a dedicated
     * top-level "Required" checkbox that already drives the input filter;
     * exposing it again here is the duplication the type-aware editor was
     * built to remove. The constant remains because legacy persisted
     * fields (and {@see FormBuilderService}) still recognise the value.
     *
     * @return list<array{type: string, label: string, requires_options: bool}>
     */
    public static function vocabulary(): array
    {
        return [
            ['type' => self::TYPE_STRING_LENGTH, 'label' => 'Min / max length', 'requires_options' => true],
            ['type' => self::TYPE_BETWEEN, 'label' => 'Number range', 'requires_options' => true],
            ['type' => self::TYPE_EMAIL, 'label' => 'Email address', 'requires_options' => false],
            ['type' => self::TYPE_URL, 'label' => 'URL', 'requires_options' => false],
            ['type' => self::TYPE_REGEX, 'label' => 'Pattern (regex)', 'requires_options' => true],
            ['type' => self::TYPE_CONFIRM, 'label' => 'Must match another field', 'requires_options' => true],
        ];
    }

    /**
     * An absolute http(s) URL. 1.x mapped this type to the Hostname
     * validator, which rejected every value with a scheme.
     */
    private static function isHttpUrl(mixed $value): bool
    {
        return (
            is_string($value)
                && false !== filter_var($value, FILTER_VALIDATE_URL)
                && 1 === preg_match('~^https?://~i', $value)
        );
    }

    /**
     * Returns null when the type does not produce a Laminas validator
     * ({@see TYPE_REQUIRED} is handled via the input filter; {@see TYPE_CONFIRM}
     * is wired by {@see \Contenir\FormBuilder\Service\FormBuilderService} as a
     * cross-field rule).
     *
     * @throws InvalidArgumentException When the type is not in the vocabulary.
     */
    public function create(ValidatorDefinition $definition): ?ValidatorInterface
    {
        $validator = match ($definition->type) {
            self::TYPE_REQUIRED, self::TYPE_CONFIRM => null,
            self::TYPE_STRING_LENGTH => $this->stringLength($definition->options),
            self::TYPE_BETWEEN => $this->between($definition->options),
            self::TYPE_EMAIL => new EmailAddress(),
            self::TYPE_URL => $this->url(),
            self::TYPE_REGEX => $this->regex($definition->options),
            default => throw new InvalidArgumentException(
                sprintf('Unknown validator type "%s"', $definition->type),
            ),
        };

        if (null !== $validator && null !== $definition->message && '' !== $definition->message) {
            $validator->setMessage($definition->message);
        }

        return $validator;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @mago-expect analysis:deprecated-class Between is deprecated from laminas-validator 2.60; NumberComparison needs a higher minimum.
     */
    private function between(array $options): Between
    {
        return new Between([
            'min'       => $options['min'] ?? 0,
            'max'       => $options['max'] ?? PHP_INT_MAX,
            'inclusive' => filter_var($options['inclusive'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @mago-expect analysis:mixed-assignment Validator options are decoded JSON; each value is checked before use.
     */
    private function regex(array $options): Regex
    {
        $pattern = $options['pattern'] ?? null;

        return new Regex(['pattern' => is_string($pattern) && '' !== $pattern ? $pattern : '/.*/']);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @mago-expect analysis:mixed-assignment Validator options are decoded JSON; each value is checked before use.
     */
    private function stringLength(array $options): StringLength
    {
        $min = $options['min'] ?? null;
        $max = $options['max'] ?? null;

        return new StringLength([
            'min' => is_numeric($min) ? (int) $min : 0,
            'max' => is_numeric($max) ? (int) $max : null,
        ]);
    }

    /**
     * @mago-expect lint:prefer-first-class-callable Xdebug records no branch coverage through a first-class callable.
     */
    private function url(): Callback
    {
        $validator = new Callback(['callback' => static fn(mixed $value): bool => self::isHttpUrl($value)]);
        $validator->setMessage('The input is not a valid http or https URL', Callback::INVALID_VALUE);

        return $validator;
    }
}
