<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Loader;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Persistence\PersistenceException;

/**
 * Reads whole form definitions, with their sections, groups, rows, fields,
 * notifications and webhooks.
 *
 * @api
 */
interface FormLoaderInterface
{
    /**
     * Every form's id, slug, title and status, by title, for menus and
     * pickers: one query, nothing else loaded.
     *
     * @return list<array{id: int, slug: string, title: string, status: string}>
     *
     * @throws PersistenceException
     */
    public function listSummaries(): array;

    /**
     * Every form, by title.
     *
     * @return list<FormDefinition>
     *
     * @throws PersistenceException
     */
    public function loadAll(): array;

    /**
     * @throws PersistenceException
     */
    public function loadById(int $formId): ?FormDefinition;

    /**
     * @throws PersistenceException
     */
    public function loadBySlug(string $slug): ?FormDefinition;
}
