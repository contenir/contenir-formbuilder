<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Export;

use Contenir\FormBuilder\Definition\FieldDefinition;
use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Persistence\PersistenceException;
use Contenir\FormBuilder\Persistence\Row;
use Contenir\FormBuilder\Repository\EntryRepositoryInterface;

use function array_map;
use function count;
use function fputcsv;
use function in_array;

/**
 * Writes a form's entries to a stream as CSV, newest first: the entry
 * number, when it was submitted and its status, then a column per field.
 *
 * Entries are read a batch at a time, with the values of a whole batch in
 * one query, and each row is written as it is read, so an export of any
 * size holds one batch in memory. Spam is left out unless asked for.
 *
 * A cell a spreadsheet would read as a formula (one starting with =, +, -,
 * @, a tab or a carriage return) is written with a single quote in front,
 * so opening the file never runs what a visitor typed into the form.
 *
 * @api
 */
final readonly class EntryCsvWriter
{
    private const array FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param positive-int $batchSize
     */
    public function __construct(
        private EntryRepositoryInterface $entries,
        private int $batchSize = 500,
    ) {}

    private static function neutralise(string $cell): string
    {
        return '' !== $cell && in_array($cell[0], self::FORMULA_TRIGGERS, strict: true) ? "'{$cell}" : $cell;
    }

    /**
     * @param list<array<string, mixed>> $values
     *
     * @return array<string, string>
     *
     * @throws PersistenceException
     */
    private static function valuesByName(array $values): array
    {
        $byName = [];
        foreach ($values as $stored) {
            $value                                = new Row($stored);
            $byName[$value->string('field_name')] = $value->nullableString('value_text')
                ?? $value->string('value_json');
        }

        return $byName;
    }

    /**
     * @param resource $stream
     * @param list<string> $excludedStatuses the statuses whose entries are left out
     *
     * @throws PersistenceException
     *
     * @mago-expect analysis:missing-parameter-type A stream is a resource, which has no native type.
     */
    public function write(
        $stream,
        FormDefinition $form,
        array $excludedStatuses = [EntryRepositoryInterface::STATUS_SPAM],
    ): void {
        $fields = array_map(static fn(FieldDefinition $field): string => $field->name, $form->getAllFields());
        $this->writeRow($stream, ['form_entry_id', 'submitted_at', 'status', ...$fields]);

        $formId   = (int) $form->id;
        $beforeId = null;
        do {
            $rows = $this->entries->listEntriesBefore($formId, $beforeId, $this->batchSize, $excludedStatuses);
            $ids  = [];
            foreach ($rows as $row) {
                $ids[] = (new Row($row))->int('form_entry_id');
            }
            $values = $this->entries->fetchValuesForEntries($ids);
            foreach ($rows as $stored) {
                $entry    = new Row($stored);
                $beforeId = $entry->int('form_entry_id');
                $byName   = self::valuesByName($values[$beforeId] ?? []);
                $line     = [(string) $beforeId, $entry->string('submitted_at'), $entry->string('status')];
                foreach ($fields as $name) {
                    $line[] = $byName[$name] ?? '';
                }
                $this->writeRow($stream, $line);
            }
        } while (count($rows) === $this->batchSize);
    }

    /**
     * @param resource $stream
     * @param list<string> $cells
     *
     * @mago-expect analysis:missing-parameter-type A stream is a resource, which has no native type.
     */
    private function writeRow($stream, array $cells): void
    {
        fputcsv($stream, array_map(self::neutralise(...), $cells), separator: ',', enclosure: '"', escape: '\\');
    }
}
