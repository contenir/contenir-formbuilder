# Storage

The form builder keeps forms and their entries in nine `form_*` tables,
through [php-db](https://github.com/php-db/phpdb), on MySQL or SQLite. The
table and column names are the ones the CMS has always used, so sites that
already have forms keep their data as it is.

## Tables

The tables are created by a php-db migration in `schema/`, under the
`Contenir\FormBuilder\Schema` namespace. A host runs it with
[peptolab/phpdb-migration](https://github.com/peptolab/phpdb-migration):

```php
use Contenir\FormBuilder\ConfigProvider;
use PhpDb\Migration\MigrationRunner;

$runner = new MigrationRunner(
    $adapter,
    ConfigProvider::schemaPath(),
    ConfigProvider::SCHEMA_NAMESPACE,
);
$runner->ensureMigrationsTable();
$runner->runPending();
```

`ConfigProvider` also lists the migrations under `contenir.schema_migrations`
for a host that collects them from its configuration.

| Table | Holds |
| --- | --- |
| `form` | A form: slug, title, layout, submit button, settings, retention period, status |
| `form_section`, `form_group`, `form_row`, `form_field` | Its layout, each level ordered by `sort` |
| `form_notification` | Its notification emails |
| `form_webhook` | Its webhooks |
| `form_entry` | A submission: when, from where, by whom, its status and meta |
| `form_entry_value` | One submitted value: text in `value_text`, a list as JSON in `value_json` |

The migration, `Version20261020120000CreateFormTables`, is the baseline: it
creates each table with the DDL the CMS's "Set up Forms" installer used, and
leaves a table that already exists exactly as it is. On a site where forms
were already set up, it changes nothing and is only recorded as run.

On SQLite, turn foreign keys on for each connection
(`PRAGMA foreign_keys = ON`) so deleting a form deletes its layout and
entries; MySQL's InnoDB enforces them already.

## Reading forms

`FormLoaderInterface` reads whole definitions; `PhpDbFormLoader` is the php-db
implementation. A form costs seven queries however big it is.

```php
$loader = new PhpDbFormLoader($adapter);
$form   = $loader->loadBySlug('contact');   // or loadById(5), loadAll()
$menu   = $loader->listSummaries();         // id, slug, title, status: one query
```

## Writing forms

`FormRepositoryInterface` makes each change an editor makes: forms, sections,
groups, rows, fields, notifications and webhooks, plus the layout editor's
inserts, moves and reorders. `$data` arrays use the column names, with
`settings`, `options`, `validators`, `filters`, `conditional`, `conditions`
and `headers` given as arrays and stored as JSON; an update changes only the
keys it is given. A change that takes several statements runs in one
transaction, or joins the caller's.

```php
$forms  = new PhpDbFormRepository($adapter, $clock);
$formId = $forms->createForm(['slug' => 'contact', 'title' => 'Contact us', 'retention_days' => 365]);
$forms->insertSection($formId, position: 0);
```

## Entries

`EntryRepositoryInterface` stores submissions and reads them back for the
entries screen. Entries come back as rows keyed by column.

| Method | Does |
| --- | --- |
| `record()` | Stores an entry and its values together |
| `listEntries()`, `countByStatus()` | A page of entries, and counts per status, filtered by status, date and search |
| `listEntriesBefore()`, `fetchValuesForEntries()` | Batches for reading every entry without OFFSET |
| `setStatus()`, `markNotified()`, `wasNotified()` | Status changes, and whether notifications have gone out |
| `delete()` | Deletes an entry and its values |
| `findExpired()`, `redact()` | Entries past a retention period, and redacting one |

Times (`submitted_at`, the retention cutoff, `notified_at`) come from the
PSR-20 clock passed to the repository, written to the second in the clock's
time zone, as the CMS has always stored them.

Every method throws `Persistence\PersistenceException` when the database
fails.

## Exporting entries

`Export\EntryCsvWriter` writes a form's entries to a stream as CSV, newest
first, a column per field. It reads 500 entries at a time, with each batch's
values in one query, so an export of any size holds one batch in memory.
Spam is left out unless asked for, and a cell that starts with `=`, `+`, `-`,
`@`, a tab or a carriage return gets a single quote in front, so a
spreadsheet never runs it as a formula.

```php
(new EntryCsvWriter($entries))->write($stream, $form);
```

## Retention and redaction

`Redaction\ExpiredEntryRedactor` redacts every entry kept longer than its
form's `retention_days`: the row stays, so counts still add up, but its
values, files, address and meta go. A form with no retention period keeps its
entries. Run it daily.

```php
$report = (new ExpiredEntryRedactor($loader, $entries, new StorageEntryFiles($storage, $logger)))->redactExpired();
echo $report->summary();   // "Redacted 12 entries on 2 forms."
```

Files are deleted first, through an `EntryFilesInterface`.
`StorageEntryFiles` deletes them from a contenir/storage storage; without one,
`NoFileStorage` (the default) keeps any entry whose upload fields hold files,
so the paths are not lost. An entry whose files cannot be deleted is kept,
counted as failed, and tried again next run.

## Status changes

`Entry\EntryStatusChanger` moves an entry to another status. When an entry
held as spam is marked complete, it runs the notification and webhook
registrars for it then, with the values it was submitted with. An entry is
sent on once at most.
