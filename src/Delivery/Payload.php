<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Delivery;

use function is_array;
use function is_string;

/**
 * Typed reads from a message's queued data, which arrives untyped (decoded
 * JSON, say).
 *
 * @internal
 */
final readonly class Payload
{
    /**
     * @param array<array-key, mixed> $data
     */
    public function __construct(
        private array $data,
    ) {}

    /**
     * @mago-expect analysis:mixed-assignment Queued data is untyped; the value is checked with is_string().
     */
    public function string(string $key): ?string
    {
        $value = $this->data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * The strings in a list; any other value in it is dropped.
     *
     * @return list<string>
     *
     * @mago-expect analysis:mixed-assignment Queued data is untyped; each value is checked with is_string().
     */
    public function strings(string $key): array
    {
        $values  = $this->data[$key] ?? null;
        $strings = [];
        foreach (is_array($values) ? $values : [] as $value) {
            if (! is_string($value)) {
                continue;
            }
            $strings[] = $value;
        }

        return $strings;
    }
}
