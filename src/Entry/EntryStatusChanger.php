<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Entry;

use ArrayObject;
use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Persistence\PersistenceException;
use Contenir\FormBuilder\Persistence\Row;
use Contenir\FormBuilder\Repository\EntryRepositoryInterface;
use Contenir\FormBuilder\Service\BuilderForm;
use SplObserver;

use function json_decode;

/**
 * Moves an entry to another status, and sends on an entry held as spam
 * when it is marked complete.
 *
 * A submission caught as spam skips its notifications and webhooks. When
 * someone marks it complete, the registrars run for it then, with the
 * values it was submitted with, as they would have at submission. Each
 * entry is sent on once at most: an entry leaving complete, or one just
 * sent on, is marked as notified, and a notified entry is never sent again
 * however often it is moved between spam and complete.
 *
 * @api
 */
final readonly class EntryStatusChanger
{
    /**
     * @param list<SplObserver> $registrars the email and webhook registrars, not the storing one
     */
    public function __construct(
        private EntryRepositoryInterface $entries,
        private array $registrars,
    ) {}

    /**
     * @return bool Whether the entry's skipped notifications and webhooks were sent
     *
     * @throws PersistenceException
     */
    public function change(FormDefinition $form, int $entryId, string $status): bool
    {
        $entry = $this->entries->findEntry($entryId);
        if (null === $entry) {
            return false;
        }
        $previous = (new Row($entry))->string('status');
        $notified = $this->entries->wasNotified($entry);

        $this->entries->setStatus($entryId, $status);
        if ($notified || $previous === $status) {
            return false;
        }
        if (EntryRepositoryInterface::STATUS_COMPLETE === $previous) {
            $this->entries->markNotified($entryId);
        }
        if (
            EntryRepositoryInterface::STATUS_SPAM !== $previous
            || EntryRepositoryInterface::STATUS_COMPLETE !== $status
        ) {
            return false;
        }

        $this->entries->markNotified($entryId);
        $this->notify($form, $entryId, new Row($entry));

        return true;
    }

    /**
     * @throws PersistenceException
     */
    private function notify(FormDefinition $form, int $entryId, Row $entry): void
    {
        $values = [];
        foreach ($this->entries->fetchValues($entryId) as $stored) {
            $value                                = new Row($stored);
            $json                                 = $value->nullableString('value_json');
            $values[$value->string('field_name')] = null === $json
                ? $value->nullableString('value_text')
                : json_decode($json, associative: true);
        }

        /** @var ArrayObject<string, mixed> $registry */
        $registry = new ArrayObject(
            [
                'form'         => $form,
                'values'       => $values,
                'spam'         => false,
                'entry_id'     => $entryId,
                'entry_status' => EntryRepositoryInterface::STATUS_COMPLETE,
                'entry'        => [
                    'id'     => $entryId,
                    'date'   => $entry->string('submitted_at'),
                    'ip'     => $entry->string('ip'),
                    'status' => EntryRepositoryInterface::STATUS_COMPLETE,
                ],
            ],
            ArrayObject::ARRAY_AS_PROPS,
        );
        $subject           = new BuilderForm();
        $subject->registry = $registry;

        foreach ($this->registrars as $registrar) {
            $registrar->update($subject);
        }
    }
}
