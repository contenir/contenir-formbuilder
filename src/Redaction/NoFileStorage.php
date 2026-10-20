<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Redaction;

use Contenir\FormBuilder\Definition\FormDefinition;
use Override;
use Psr\Log\LoggerInterface;

/**
 * For a host with no file storage: an entry with no files is redacted, but
 * one whose upload fields hold paths is kept and logged, so the paths to
 * files nobody can delete here are not lost.
 *
 * @api
 */
final readonly class NoFileStorage implements EntryFilesInterface
{
    public function __construct(
        private ?LoggerInterface $log = null,
    ) {}

    #[Override]
    public function delete(FormDefinition $form, int $entryId, array $values): bool
    {
        if ([] === UploadPaths::of($form, $values)) {
            return true;
        }

        $this->log?->warning(
            "Entry {$entryId} of form \"{$form->slug}\" was not redacted: it has files and no file storage is configured",
        );

        return false;
    }
}
