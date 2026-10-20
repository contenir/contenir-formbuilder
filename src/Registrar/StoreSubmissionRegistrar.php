<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Registrar;

use Contenir\FormBuilder\Persistence\PersistenceException;
use Contenir\FormBuilder\Repository\EntryRepositoryInterface;
use Override;
use SplObserver;
use SplSubject;

use function is_array;
use function is_scalar;

/**
 * Stores a submission as an entry: complete, or spam when the honeypot
 * caught it, with the visitor's address, user id and meta from the
 * submission's context.
 *
 * It writes the new entry's id and status back to the registry as
 * `entry_id` and `entry_status`, so attach it first: the registrars after
 * it, and `{entry:id}` in their templates, then see the stored entry.
 *
 * A database failure is not caught: a submission that cannot be stored
 * should fail, not be lost.
 *
 * @api
 */
final readonly class StoreSubmissionRegistrar implements SplObserver
{
    public function __construct(
        private EntryRepositoryInterface $entries,
    ) {}

    /**
     * @throws PersistenceException
     *
     * @mago-expect analysis:mixed-assignment The context is untyped; the address and user id are checked with is_scalar().
     */
    #[Override]
    public function update(SplSubject $subject): void
    {
        $submission = Submission::of($subject);
        if (
            null === $submission
            || null === $submission->form->id
            || ! is_array($submission->registry['values'] ?? null)
        ) {
            return;
        }

        $ip     = $submission->context['ip'] ?? null;
        $userId = $submission->context['user_id'] ?? null;
        $status = $submission->spam ? EntryRepositoryInterface::STATUS_SPAM : EntryRepositoryInterface::STATUS_COMPLETE;

        $submission->registry->offsetSet('entry_id', $this->entries->record(
            $submission->form->id,
            $submission->values,
            $status,
            is_scalar($ip) ? (string) $ip : null,
            is_scalar($userId) ? (int) $userId : null,
            Submission::named($submission->context['meta'] ?? null),
        ));
        $submission->registry->offsetSet('entry_status', $status);
    }
}
