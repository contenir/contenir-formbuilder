<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Service;

use Laminas\Form\FormInterface;

/**
 * Outcome of a single submission attempt.
 *
 * `valid` is true when the data passed validation AND the request was not
 * flagged as spam. A spam-flagged submission appears as `valid=false` with
 * `isSpam=true` so callers can return a generic success response without
 * leaking the spam classification to bots.
 *
 * @api
 */
final class SubmissionResult
{
    /**
     * @param array<string, mixed> $values
     * @param array<string, array<string, string>|string> $errors
     *
     * @mago-expect lint:excessive-parameter-list Immutable value object; the promoted constructor is its public shape and is called with named arguments.
     */
    public function __construct(
        public readonly bool $valid,
        public readonly FormInterface $form,
        public readonly array $values = [],
        public readonly array $errors = [],
        public readonly bool $isSpam = false,
        public readonly ?int $entryId = null,
    ) {}
}
