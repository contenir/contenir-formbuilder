<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Redaction;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\Storage\Exception\NotFoundException;
use Contenir\Storage\StorageInterface;
use Override;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Deletes an entry's files from the contenir/storage storage its uploads
 * were stored on. A file already missing counts as deleted; any other
 * failure is logged, and the entry kept.
 *
 * @api
 */
final readonly class StorageEntryFiles implements EntryFilesInterface
{
    public function __construct(
        private StorageInterface $files,
        private ?LoggerInterface $log = null,
    ) {}

    #[Override]
    public function delete(FormDefinition $form, int $entryId, array $values): bool
    {
        foreach (UploadPaths::of($form, $values) as $path) {
            try {
                $this->files->delete($path);
            } catch (NotFoundException) {
                continue;
            } catch (Throwable $e) {
                $this->log?->warning(
                    "Entry {$entryId} of form \"{$form->slug}\" was not redacted: "
                        . "its file {$path} could not be deleted: {$e->getMessage()}",
                );

                return false;
            }
        }

        return true;
    }
}
