<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Definition;

use function is_array;
use function is_scalar;

/**
 * Immutable description of a single validator applied to a field.
 *
 * The {@see $type} value is one of the curated UI keys (`required`,
 * `string_length`, `between`, `email`, `url`, `regex`, `confirm`); the
 * Builder service maps each type onto a Zend validator at form-build time.
 *
 * @phpstan-type ValidatorArray array{type: string, options?: array<string, mixed>, message?: string|null}
 *
 * @api
 */
final class ValidatorDefinition
{
    /** @param array<string, mixed> $options */
    public function __construct(
        public readonly string $type,
        public readonly array $options = [],
        public readonly ?string $message = null,
    ) {}

    /**
     * Build from a decoded `{type, options?, message?}` array. The input is
     * usually decoded JSON, so each key is coerced: a scalar `type` or
     * `message` becomes a string, a non-array `options` becomes `[]`.
     *
     * @param array<array-key, mixed> $data
     *
     * @mago-expect analysis:mixed-assignment Decoded JSON is untyped; each value is checked before use.
     */
    public static function fromArray(array $data): self
    {
        $type    = $data['type'] ?? '';
        $options = $data['options'] ?? [];
        $message = $data['message'] ?? null;

        return new self(
            type: is_scalar($type) ? (string) $type : '',
            options: is_array($options) ? self::stringKeyed($options) : [],
            message: is_scalar($message) ? (string) $message : null,
        );
    }

    /**
     * @param array<array-key, mixed> $options
     *
     * @return array<string, mixed>
     *
     * @mago-expect analysis:mixed-assignment Option values are opaque; only the keys are normalised.
     */
    private static function stringKeyed(array $options): array
    {
        $out = [];
        foreach ($options as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /** @return ValidatorArray */
    public function toArray(): array
    {
        return [
            'type'    => $this->type,
            'options' => $this->options,
            'message' => $this->message,
        ];
    }
}
