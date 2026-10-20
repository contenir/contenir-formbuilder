<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Redaction;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Loader\FormLoaderInterface;
use Contenir\FormBuilder\Persistence\PersistenceException;
use Contenir\FormBuilder\Persistence\Row;
use Contenir\FormBuilder\Repository\EntryRepositoryInterface;

/**
 * Redacts the entries every form with a retention period has kept for
 * longer than that period, as {@see EntryRepositoryInterface::redact()}
 * does: the entry's row stays, so counts still add up, but its values are
 * deleted, along with the files its upload fields point to, and its address
 * and meta are cleared. A form with no retention period keeps its entries.
 *
 * Files are deleted first. An entry whose files cannot all be deleted keeps
 * its values, so the paths to those files are not lost, and is counted as
 * failed; the next run tries it again. How old is too old is the entry
 * repository's clock's call.
 *
 * @api
 */
final readonly class ExpiredEntryRedactor
{
    public function __construct(
        private FormLoaderInterface $forms,
        private EntryRepositoryInterface $entries,
        private EntryFilesInterface $files = new NoFileStorage(),
    ) {}

    /**
     * @throws PersistenceException
     */
    public function redactExpired(): RedactionReport
    {
        $report = new RedactionReport();
        foreach ($this->forms->loadAll() as $form) {
            if (null === $form->id || null === $form->retentionDays || $form->retentionDays < 1) {
                continue;
            }
            [$redacted, $kept] = $this->redactForm($form, $form->id, $form->retentionDays);
            $report = $report->add($redacted, $kept);
        }

        return $report;
    }

    /**
     * @return array{int, int} entries redacted, entries kept
     *
     * @throws PersistenceException
     */
    private function redactForm(FormDefinition $form, int $formId, int $retentionDays): array
    {
        $ids = [];
        foreach ($this->entries->findExpired($formId, $retentionDays) as $entry) {
            $ids[] = (new Row($entry))->int('form_entry_id');
        }
        $values = $this->entries->fetchValuesForEntries($ids);

        $redacted = 0;
        $kept     = 0;
        foreach ($ids as $entryId) {
            if (! $this->files->delete($form, $entryId, $values[$entryId] ?? [])) {
                $kept++;

                continue;
            }
            $this->entries->redact($entryId);
            $redacted++;
        }

        return [$redacted, $kept];
    }
}
