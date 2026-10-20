<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Repository;

use PhpDb\Sql\Predicate\Like;
use PhpDb\Sql\Predicate\Operator;
use PhpDb\Sql\Predicate\PredicateSet;
use PhpDb\Sql\Where;

use function ctype_digit;

/**
 * The filter the entries screen applies, shared by the list and the counts:
 * one form's entries, in any of the statuses given (all when there are none),
 * submitted on or after a date, and matching a search. A search of digits
 * matches the entry id or part of the address, so "12" finds entry 12 and
 * 10.0.0.12; any other search matches part of the address.
 *
 * @internal
 */
final readonly class EntryFilter
{
    /**
     * @param list<string>|null $statuses
     */
    public function __construct(
        private int $formId,
        private ?array $statuses = null,
        private ?string $sinceDate = null,
        private ?string $search = null,
    ) {}

    public function apply(Where $where): void
    {
        $where->equalTo('form_id', $this->formId);
        if (null !== $this->statuses && [] !== $this->statuses) {
            $where->in('status', $this->statuses);
        }
        if (null !== $this->sinceDate && '' !== $this->sinceDate) {
            $where->greaterThanOrEqualTo('submitted_at', $this->sinceDate);
        }
        if (null === $this->search || '' === $this->search) {
            return;
        }

        $address = new Like('ip', "%{$this->search}%");
        $where->addPredicate(
            ctype_digit($this->search)
                ? new PredicateSet(
                    [new Operator('form_entry_id', Operator::OPERATOR_EQUAL_TO, (int) $this->search), $address],
                    PredicateSet::COMBINED_BY_OR,
                )
                : $address,
        );
    }
}
