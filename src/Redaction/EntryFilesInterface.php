<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Redaction;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Persistence\PersistenceException;

/**
 * Deletes the files an entry's upload fields point to, before the entry is
 * redacted and those paths are lost.
 *
 * @api
 */
interface EntryFilesInterface
{
    /**
     * @param list<array<string, mixed>> $values the entry's stored values
     *
     * @return bool Whether every file is gone: deleted now, or already missing
     *
     * @throws PersistenceException When a stored value cannot be read.
     */
    public function delete(FormDefinition $form, int $entryId, array $values): bool;
}
