<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Service;

use ArrayObject;
use Contenir\FormBuilder\Conditional\RuleEvaluator;
use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\Storage\Exception\StorageException;
use Contenir\Storage\StorageManager;
use Contenir\Storage\UploadInput;
use DateTimeImmutable;
use Laminas\Form\Exception\ExceptionInterface as FormException;
use Laminas\Form\FormInterface;
use SplObserver;

use function array_diff;
use function array_keys;
use function array_values;
use function is_array;
use function is_int;
use function is_string;
use function is_uploaded_file;
use function trim;

use const UPLOAD_ERR_NO_FILE;
use const UPLOAD_ERR_OK;

/**
 * Coordinates server-side form submission: build, validate, dispatch.
 *
 * The service owns the spam detection rule (a non-empty honeypot) and the
 * SplSubject/Observer wiring so registrars don't need to know how to inspect
 * the Laminas form internals. Returns a {@see SubmissionResult} the caller
 * uses to decide on success/failure rendering.
 *
 * Optional `$storageManager` is consulted for `file` field uploads. When
 * null (or unconfigured for the default profile), file uploads are
 * silently skipped — this keeps the engine usable in tests and in
 * deployments that don't accept uploads.
 *
 * @api
 *
 * @mago-expect lint:cyclomatic-complexity Kept whole for 2.0 (owns the whole submit pipeline); splitting it is a proposed follow-up.
 * @mago-expect lint:kan-defect Kept whole for 2.0 (owns the whole submit pipeline); splitting it is a proposed follow-up.
 * @mago-expect lint:too-many-methods Kept whole for 2.0 (owns the whole submit pipeline); splitting it is a proposed follow-up.
 */
class FormSubmissionService
{
    /** @var list<SplObserver> */
    private array $observers = [];

    private RuleEvaluator $conditionalEvaluator;

    public function __construct(
        private FormBuilderService $builder,
        private ?StorageManager $storageManager = null,
    ) {
        $this->conditionalEvaluator = new RuleEvaluator();
    }

    public function attach(SplObserver $observer): void
    {
        $this->observers[] = $observer;
    }

    /**
     * Observers are notified only for a valid or spam-flagged submission, and
     * only when the builder returns a {@see BuilderForm}. The submission
     * timestamp is captured once, so `{entry:date}` reads the same for every
     * observer. The registry's `entry` attributes are seeded before the first
     * observer and refreshed after each one, so `{entry:id}` picks up the
     * `entry_id` a storing registrar (which runs first by convention) writes.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files    Shape of `$_FILES` — keyed by field name,
     *                                       each value `{name, type, tmp_name, error, size}`.
     * @param array<string, mixed> $context  ip, user_id, meta
     *
     * @throws FormException When Laminas rejects the built form.
     * @throws StorageException When a configured storage backend cannot store an upload.
     *
     * @mago-expect analysis:less-specific-argument Laminas returns nested messages and data keyed by element name.
     * @mago-expect analysis:mixed-assignment Form data is untyped; it is checked with is_array().
     */
    public function submit(
        FormDefinition $form,
        array $post,
        array $files = [],
        array $context = [],
    ): SubmissionResult {
        $built = $this->builder->build($form);

        $isSpam = $this->detectSpam($post);
        if ($isSpam) {
            unset($post[FormBuilderService::HONEYPOT_NAME]);
        }

        $post         = $this->processFileUploads($form, $post, $files);
        $hiddenFields = $this->applyConditionalGating($built, $form, $post);

        $built->setData($post);
        $valid = $built->isValid();

        if (! $valid && ! $isSpam) {
            return new SubmissionResult(
                valid: false,
                form: $built,
                values: [],
                errors: $built->getMessages(),
                isSpam: false,
            );
        }

        $data   = $valid ? $built->getData(FormInterface::VALUES_NORMALIZED) : $post;
        $values = $this->withoutInternalValues(is_array($data) ? $data : $post);
        foreach ($hiddenFields as $name) {
            unset($values[$name]);
        }

        if ($built instanceof BuilderForm) {
            $this->notifyObservers($built, $form, $values, $isSpam, $context);
        }

        return new SubmissionResult(
            valid: ! $isSpam,
            form: $built,
            values: $values,
            errors: [],
            isSpam: $isSpam,
            entryId: $this->extractEntryId($built),
        );
    }

    /**
     * Whether `$path` is a file PHP received through an HTTP upload. Only
     * such files are handed to storage; overridable so the upload path can
     * be exercised without a real request.
     */
    protected function isUploadedFile(string $path): bool
    {
        return is_uploaded_file($path);
    }

    /**
     * Excludes every field whose conditional rule fails against the submitted
     * data from the form's validation group. Returns the names of those hidden
     * fields so the caller can drop their values from the submission payload:
     * leftover state in $post for a hidden field must not be persisted.
     *
     * @param array<string, mixed> $post
     *
     * @return list<string>
     */
    private function applyConditionalGating(FormInterface $built, FormDefinition $form, array $post): array
    {
        $inputFilter = $built->getInputFilter();
        $hidden      = [];

        foreach ($form->getAllFields() as $field) {
            if (null === $field->conditional || ! $inputFilter->has($field->name)) {
                continue;
            }

            if (! $this->conditionalEvaluator->shouldShow($field->conditional, $post)) {
                $hidden[] = $field->name;
            }
        }

        if ([] !== $hidden) {
            $allNames = array_keys($built->getElements());
            $built->setValidationGroup(array_values(array_diff($allNames, $hidden)));
        }

        return $hidden;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array{id: int|null, date: string, ip: mixed, status: mixed}
     */
    private function buildEntryAttributes(BuilderForm $form, array $context, string $date): array
    {
        return [
            'id'     => $this->extractEntryId($form),
            'date'   => $date,
            'ip'     => $context['ip'] ?? '',
            'status' => $form->registry['entry_status'] ?? 'complete',
        ];
    }

    /**
     * @param array<string, mixed> $post
     *
     * @mago-expect analysis:mixed-assignment POST values are untyped; the honeypot is checked with is_string().
     */
    private function detectSpam(array $post): bool
    {
        $honeypot = $post[FormBuilderService::HONEYPOT_NAME] ?? '';

        return ! is_string($honeypot) || trim($honeypot) !== '';
    }

    /**
     * @mago-expect analysis:mixed-assignment The registry is an untyped bag; the id is checked with is_int().
     */
    private function extractEntryId(FormInterface $form): ?int
    {
        if (! $form instanceof BuilderForm || ! $form->registry instanceof ArrayObject) {
            return null;
        }

        $entryId = $form->registry['entry_id'] ?? null;

        return is_int($entryId) ? $entryId : null;
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $context
     */
    private function notifyObservers(
        BuilderForm $built,
        FormDefinition $form,
        array $values,
        bool $isSpam,
        array $context,
    ): void {
        /** @var ArrayObject<string, mixed> $registry */
        $registry = new ArrayObject(
            [
                'form'    => $form,
                'values'  => $values,
                'spam'    => $isSpam,
                'context' => $context,
            ],
            ArrayObject::ARRAY_AS_PROPS,
        );

        $built->registry = $registry;

        $submittedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->updateEntryRegistry($built, $context, $submittedAt);
        foreach ($this->observers as $observer) {
            $observer->update($built);
            $this->updateEntryRegistry($built, $context, $submittedAt);
        }
    }

    /**
     * Stash any uploaded files via the project's Storage layer before
     * validation runs, replacing the file-field's value in $post with
     * the relative storage path. After this step the form treats file
     * fields like any other scalar — validation, conditional gating,
     * persistence and notifications all see a string path rather than
     * an upload struct, so none of them need a special case.
     *
     * Files land under `forms/<form-slug>/` in the configured Storage
     * profile; the storage layer disambiguates filenames so multiple
     * submissions to the same form can't overwrite each other.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     *
     * @return array<string, mixed>
     *
     * @throws StorageException
     *
     * @mago-expect analysis:mixed-assignment `$_FILES` entries are untyped; each key is checked before use.
     */
    private function processFileUploads(FormDefinition $form, array $post, array $files): array
    {
        foreach ($form->getAllFields() as $field) {
            $upload = $files[$field->name] ?? null;
            if ('file' !== $field->type || ! is_array($upload)) {
                continue;
            }

            $error   = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
            $tmpPath = $upload['tmp_name'] ?? '';
            if (
                UPLOAD_ERR_OK !== $error
                || ! is_string($tmpPath)
                || '' === $tmpPath
                || ! $this->isUploadedFile($tmpPath)
            ) {
                continue;
            }

            $name   = $upload['name'] ?? null;
            $type   = $upload['type'] ?? null;
            $stored = $this->storeUpload(
                $form,
                $tmpPath,
                is_string($name) ? $name : 'upload',
                is_string($type) && '' !== $type ? $type : null,
            );
            if (null !== $stored) {
                $post[$field->name] = $stored;
            }
        }

        return $post;
    }

    /**
     * @throws StorageException
     */
    private function storeUpload(FormDefinition $form, string $tmpPath, string $clientName, ?string $mimeType): ?string
    {
        if (null === $this->storageManager || ! $this->storageManager->has(StorageManager::DEFAULT_PROFILE)) {
            return null;
        }

        $entry = $this->storageManager
            ->get(StorageManager::DEFAULT_PROFILE)
            ->store(new UploadInput($tmpPath, $clientName, $mimeType), 'forms/' . trim($form->slug, characters: '/'));

        return $entry->path;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function updateEntryRegistry(BuilderForm $form, array $context, string $date): void
    {
        $form->registry?->offsetSet('entry', $this->buildEntryAttributes($form, $context, $date));
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function withoutInternalValues(array $values): array
    {
        unset($values[FormBuilderService::CSRF_NAME], $values[FormBuilderService::HONEYPOT_NAME], $values['_submit']);

        return $values;
    }
}
