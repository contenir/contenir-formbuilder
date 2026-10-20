<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Redaction;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Persistence\PersistenceException;
use Contenir\FormBuilder\Persistence\Row;

use function array_merge;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function ltrim;

/**
 * The storage paths an entry's upload fields hold. An upload field holds a
 * path, or a list of them; a value in any other field is not a path,
 * whatever it looks like.
 *
 * @internal
 */
final class UploadPaths
{
    /**
     * @param list<array<string, mixed>> $values the entry's stored values
     *
     * @return list<string>
     *
     * @throws PersistenceException
     */
    public static function of(FormDefinition $form, array $values): array
    {
        $uploads = [];
        foreach ($form->getAllFields() as $field) {
            if ('file' !== $field->type) {
                continue;
            }
            $uploads[] = $field->name;
        }

        $paths = [];
        foreach ($values as $stored) {
            $value = new Row($stored);
            if (! in_array($value->string('field_name'), $uploads, strict: true)) {
                continue;
            }
            $paths[] = self::paths($value);
        }

        return array_merge(...$paths);
    }

    /**
     * @return list<string>
     *
     * @throws PersistenceException
     *
     * @mago-expect analysis:mixed-assignment Stored JSON is untyped; each path is checked with is_string().
     */
    private static function paths(Row $value): array
    {
        $json   = $value->nullableString('value_json');
        $stored = $value->nullableString('value_text')
            ?? (null === $json ? null : json_decode($json, associative: true));
        $paths = [];
        foreach (is_array($stored) ? $stored : [$stored] as $path) {
            $path = is_string($path) ? ltrim($path, characters: '/ ') : '';
            if ('' === $path) {
                continue;
            }
            $paths[] = $path;
        }

        return $paths;
    }
}
