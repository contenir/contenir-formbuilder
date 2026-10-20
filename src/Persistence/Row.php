<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Persistence;

use function array_is_list;
use function is_bool;
use function is_int;
use function is_scalar;
use function is_string;
use function preg_match;

/**
 * Typed reads from a row of the form builder's tables, whichever driver
 * fetched it: MySQL may return numbers as strings, SQLite as integers. A
 * missing column reads as NULL, so a column added later reads as its default
 * until it is there.
 *
 * @internal
 */
final readonly class Row
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(
        private array $values,
    ) {}

    /**
     * True for a non-zero number, $default for NULL.
     *
     * @throws PersistenceException
     *
     * @mago-expect analysis:mixed-assignment Column values are untyped; the type is checked here.
     */
    public function bool(string $column, bool $default): bool
    {
        $value = $this->values[$column] ?? null;

        return match (true) {
            null === $value => $default,
            is_bool($value) => $value,
            default         => 0 !== $this->int($column),
        };
    }

    /**
     * $default for NULL.
     *
     * @throws PersistenceException
     */
    public function int(string $column, int $default = 0): int
    {
        return $this->nullableInt($column) ?? $default;
    }

    /**
     * A JSON array of values, in order; any keys it had are dropped. Empty
     * for NULL or anything else.
     *
     * @return list<mixed>
     */
    public function jsonList(string $column): array
    {
        $decoded = Json::decode($this->values[$column] ?? null) ?? [];

        return array_is_list($decoded) ? $decoded : [];
    }

    /**
     * A JSON object, with its string keys only. Empty for NULL or anything
     * else.
     *
     * @return array<string, mixed>
     */
    public function jsonMap(string $column): array
    {
        return $this->nullableJsonMap($column) ?? [];
    }

    /**
     * @throws PersistenceException
     *
     * @mago-expect analysis:mixed-assignment Column values are untyped; the type is checked here.
     */
    public function nullableInt(string $column): ?int
    {
        $value = $this->values[$column] ?? null;

        return match (true) {
            null === $value => null,
            is_int($value) => $value,
            is_string($value) && 1 === preg_match('/^-?\d+$/D', $value) => (int) $value,
            default => throw PersistenceException::unreadable($column, 'an integer'),
        };
    }

    /**
     * A JSON object, with its string keys only, or null when the column is
     * NULL, empty or not JSON.
     *
     * @return array<string, mixed>|null
     *
     * @mago-expect analysis:mixed-assignment Decoded JSON is untyped; only its string keys are kept.
     */
    public function nullableJsonMap(string $column): ?array
    {
        $decoded = Json::decode($this->values[$column] ?? null);
        if (null === $decoded) {
            return null;
        }

        $map = [];
        foreach ($decoded as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            $map[$key] = $value;
        }

        return $map;
    }

    /**
     * @throws PersistenceException
     *
     * @mago-expect analysis:mixed-assignment Column values are untyped; the type is checked here.
     */
    public function nullableString(string $column): ?string
    {
        $value = $this->values[$column] ?? null;

        return match (true) {
            null === $value => null,
            is_scalar($value) => (string) $value,
            default           => throw PersistenceException::unreadable($column, 'a string'),
        };
    }

    /**
     * $default for NULL.
     *
     * @throws PersistenceException
     */
    public function string(string $column, string $default = ''): string
    {
        return $this->nullableString($column) ?? $default;
    }
}
