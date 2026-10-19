<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Validator;

use finfo;
use Laminas\Validator\ValidatorInterface;
use Override;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function filesize;
use function filter_var;
use function implode;
use function in_array;
use function is_array;
use function is_file;
use function is_string;
use function ltrim;
use function pathinfo;
use function round;
use function sprintf;
use function strtolower;
use function trim;

use const FILEINFO_MIME_TYPE;
use const FILTER_VALIDATE_INT;
use const PATHINFO_EXTENSION;

/**
 * Checks an upload, given as a `$_FILES`-style entry, against a maximum size
 * and optional lists of allowed extensions and MIME types.
 *
 * The size is read from the temporary file and the MIME type is detected from
 * its contents with finfo; the client's `size` and `type` are ignored. The
 * extension is taken from the client's file name. An empty list allows any
 * extension or type.
 *
 * @api
 */
final class FileUpload implements ValidatorInterface
{
    public const string INVALID   = 'fileInvalid';
    public const string TOO_LARGE = 'fileTooLarge';
    public const string EXTENSION = 'fileExtension';
    public const string MIME_TYPE = 'fileMimeType';

    /** 10 MB. */
    public const int DEFAULT_MAX_SIZE = 10_485_760;

    private const int KILOBYTE = 1024;
    private const int MEGABYTE = 1_048_576;

    /** @var list<string> */
    private readonly array $extensions;

    /** @var array<string, string> */
    private array $messages = [];

    /** @var list<string> */
    private readonly array $mimeTypes;

    /**
     * Extensions may be given with or without the leading dot; both lists are
     * compared case-insensitively.
     *
     * @param list<string> $extensions
     * @param list<string> $mimeTypes
     */
    public function __construct(
        private readonly int $maxSize = self::DEFAULT_MAX_SIZE,
        array $extensions = [],
        array $mimeTypes = [],
    ) {
        $this->extensions = array_map(static fn(string $ext): string => strtolower(ltrim(
            trim($ext),
            characters: '.',
        )), $extensions);
        $this->mimeTypes = array_map(static fn(string $type): string => strtolower(trim($type)), $mimeTypes);
    }

    /**
     * Builds the check from a `file` field's options: `max_size` in bytes (a
     * missing value, or one that is not a positive whole number, means
     * {@see DEFAULT_MAX_SIZE}), and `extensions` and `mime_types`, each a list
     * or a comma-separated string (missing or empty allows any).
     *
     * @param array<string, mixed> $options
     */
    public static function fromOptions(array $options): self
    {
        $options = [...['max_size' => null, 'extensions' => null, 'mime_types' => null], ...$options];
        $maxSize = filter_var($options['max_size'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return new self(
            maxSize: false === $maxSize ? self::DEFAULT_MAX_SIZE : $maxSize,
            extensions: self::optionList($options['extensions']),
            mimeTypes: self::optionList($options['mime_types']),
        );
    }

    private static function describeSize(int $bytes): string
    {
        if ($bytes >= self::MEGABYTE) {
            return round($bytes / self::MEGABYTE, precision: 1) . ' MB';
        }

        return round($bytes / self::KILOBYTE, precision: 1) . ' KB';
    }

    /**
     * The non-empty strings in a list or comma-separated string, trimmed.
     *
     * @return list<string>
     */
    private static function optionList(mixed $value): array
    {
        $entries = match (true) {
            is_string($value) => explode(',', $value),
            is_array($value)  => $value,
            default           => [],
        };
        $strings = array_map(trim(...), array_filter($entries, is_string(...)));

        return array_values(array_filter($strings, static fn(string $entry): bool => '' !== $entry));
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * @mago-expect analysis:mixed-assignment `$_FILES` entries are untyped; each key is checked before use.
     * @mago-expect analysis:possibly-false-operand filesize() only fails if the file checked above has gone.
     */
    #[Override]
    public function isValid(mixed $value): bool
    {
        $this->messages = [];

        $path = is_array($value) ? $value['tmp_name'] ?? null : null;
        if (! is_string($path) || ! is_file($path)) {
            $this->messages[self::INVALID] = 'The file could not be read. Please upload it again.';

            return false;
        }

        if (filesize($path) > $this->maxSize) {
            $this->messages[self::TOO_LARGE] = sprintf(
                'The file is larger than the %s limit.',
                self::describeSize($this->maxSize),
            );
        }

        $name      = $value['name'] ?? null;
        $extension = strtolower(pathinfo(is_string($name) ? $name : '', PATHINFO_EXTENSION));
        if ([] !== $this->extensions && ! in_array($extension, $this->extensions, strict: true)) {
            $this->messages[self::EXTENSION] = sprintf(
                'Only these file types can be uploaded: .%s.',
                implode(', .', $this->extensions),
            );
        }

        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if ([] !== $this->mimeTypes && ! in_array($mimeType, $this->mimeTypes, strict: true)) {
            $this->messages[self::MIME_TYPE] = sprintf(
                'The file\'s contents are not an allowed type (%s).',
                implode(', ', $this->mimeTypes),
            );
        }

        return [] === $this->messages;
    }
}
