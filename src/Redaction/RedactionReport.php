<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Redaction;

use function sprintf;

/**
 * What one run of {@see ExpiredEntryRedactor} did: how many entries it
 * redacted, on how many forms, and how many it had to leave because their
 * files could not be deleted.
 *
 * @api
 */
final readonly class RedactionReport
{
    public function __construct(
        public int $entries = 0,
        public int $forms = 0,
        public int $failed = 0,
    ) {}

    private static function count(int $count, string $one, string $many): string
    {
        return sprintf('%d %s', $count, 1 === $count ? $one : $many);
    }

    /**
     * This report with one more form's outcome added; a form counts when
     * entries on it were redacted.
     */
    public function add(int $redacted, int $kept): self
    {
        return new self(
            $this->entries + $redacted,
            0 < $redacted ? $this->forms + 1 : $this->forms,
            $this->failed + $kept,
        );
    }

    /**
     * The run's outcome in a sentence, for a toast or the command line.
     */
    public function summary(): string
    {
        $summary = 0 === $this->entries
            ? 'No entries are past their form\'s retention period.'
            : sprintf(
                'Redacted %s on %s.',
                self::count($this->entries, one: 'entry', many: 'entries'),
                self::count($this->forms, one: 'form', many: 'forms'),
            );

        if (0 < $this->failed) {
            $one     = 1 === $this->failed;
            $summary .= sprintf(
                ' %s kept because %s could not be deleted; %s tried again next time.',
                self::count($this->failed, one: 'entry was', many: 'entries were'),
                $one ? 'its files' : 'their files',
                $one ? 'it will be' : 'they will be',
            );
        }

        return $summary;
    }
}
