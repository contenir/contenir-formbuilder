<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Persistence;

use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * JSON as the form builder's `*_json` columns hold it: unescaped slashes and
 * Unicode, and NULL rather than an empty array or object.
 *
 * @internal
 */
final class Json
{
    /**
     * A column value as an array, or null when it is empty, NULL or not a
     * JSON array or object.
     *
     * @return array<array-key, mixed>|null
     *
     * @mago-expect analysis:mixed-assignment Decoded JSON is untyped; only an array is returned.
     */
    public static function decode(mixed $value): ?array
    {
        if (! is_string($value) || '' === $value) {
            return null;
        }
        $decoded = json_decode($value, associative: true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * An array as a column value: NULL when it is empty or cannot be encoded.
     *
     * @param array<array-key, mixed> $value
     */
    public static function encode(array $value): ?string
    {
        if ([] === $value) {
            return null;
        }
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return false === $encoded ? null : $encoded;
    }
}
