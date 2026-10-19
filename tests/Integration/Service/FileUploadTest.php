<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Integration\Service;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\FieldType\FieldTypeRegistry;
use Contenir\FormBuilder\Service\FormBuilderService;
use Contenir\FormBuilder\Service\FormSubmissionService;
use Contenir\FormBuilder\Tests\TestAsset\Factory\FormDefinitionFactory as F;
use Contenir\FormBuilder\Tests\TestAsset\Storage\RecordingUploadResolver;
use Contenir\FormBuilder\Tests\Trait\InMemorySessionTrait;
use Contenir\FormBuilder\Tests\Trait\TemporaryDirectoryTrait;
use Contenir\FormBuilder\Validator\FileUpload;
use Contenir\FormBuilder\Validator\ValidatorFactory;
use Contenir\Storage\Adapter\InMemoryStorage;
use Contenir\Storage\StorageManager;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_map;
use function fclose;
use function file_put_contents;
use function fopen;
use function ftruncate;
use function is_file;

use const UPLOAD_ERR_NO_FILE;
use const UPLOAD_ERR_OK;

#[Group('integration')]
#[Group('service')]
final class FileUploadTest extends TestCase
{
    use InMemorySessionTrait;
    use TemporaryDirectoryTrait;

    private FormBuilderService $builder;

    private InMemoryStorage $storage;

    private StorageManager $manager;

    private string $upload;

    /**
     * @return array<string, array{mixed, ?string}>
     */
    public static function clientTypeProvider(): array
    {
        return [
            'client type'     => ['application/pdf', 'application/pdf'],
            'empty type'      => ['', null],
            'missing type'    => [null, null],
            'non-string type' => [['application/pdf'], null],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function disallowedUploadProvider(): array
    {
        return [
            'over the field limit'     => [['max_size' => 4], FileUpload::TOO_LARGE],
            'disallowed extension'     => [['extensions' => 'docx'], FileUpload::EXTENSION],
            'disallowed detected type' => [['mime_types' => 'image/png'], FileUpload::MIME_TYPE],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function precedingUploadProvider(): array
    {
        return [
            'no upload for the earlier field'     => [[]],
            'failed upload for the earlier field' => [['photo' => ['error' => UPLOAD_ERR_NO_FILE]]],
        ];
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function skippedUploadProvider(): array
    {
        return [
            'upload error'         => [['error' => UPLOAD_ERR_NO_FILE]],
            'missing error code'   => [['error' => null]],
            'empty temporary path' => [['tmp_name' => '']],
            'non-string temp path' => [['tmp_name' => ['x']]],
            'not an uploaded file' => [['tmp_name' => '/nonexistent/upload']],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function spamProvider(): array
    {
        return [
            'valid spam'   => ['Ann', 'bot'],
            'invalid spam' => ['', 'bot'],
        ];
    }

    #[Test]
    public function allowedUploadIsStored(): void
    {
        $form = F::form([F::field('file', 'cv', options: [
            'max_size'   => 8,
            'extensions' => 'pdf',
            'mime_types' => 'application/pdf',
        ])]);
        $service = $this->acceptingService($this->manager);

        $result = $service->submit($form, $this->post($form), ['cv' => $this->file()]);

        static::assertTrue($result->valid);
        static::assertSame('forms/contact/cv.pdf', $result->values['cv']);
    }

    #[Test]
    #[DataProvider('clientTypeProvider')]
    public function clientTypeIsPassedToStorageOnlyWhenItIsANonEmptyString(mixed $type, ?string $expected): void
    {
        $resolver = new RecordingUploadResolver();
        $manager  = new StorageManager();
        $manager->register(StorageManager::DEFAULT_PROFILE, new InMemoryStorage(resolver: $resolver));
        $form    = $this->uploadForm();
        $service = $this->acceptingService($manager);

        $service->submit($form, $this->post($form), ['cv' => $this->file(['type' => $type])]);

        static::assertSame([$expected], array_map(static fn($upload) => $upload->clientMime, $resolver->uploads));
    }

    /**
     * @param array<string, mixed> $options
     */
    #[Test]
    #[DataProvider('disallowedUploadProvider')]
    public function disallowedUploadIsRejectedAndNotStored(array $options, string $error): void
    {
        $form    = F::form([F::field('file', 'cv', options: $options)]);
        $service = $this->acceptingService($this->manager);

        $result = $service->submit($form, $this->post($form), ['cv' => $this->file(['type' => 'image/png'])]);

        static::assertFalse($result->valid);
        static::assertSame([$error], array_keys($result->errors['cv']));
        static::assertFalse($this->storage->exists('forms/contact/cv.pdf'));
    }

    #[Test]
    public function emptyClientTypeIsLeftToTheStorageBackend(): void
    {
        $form    = $this->uploadForm();
        $service = $this->acceptingService($this->manager);

        $service->submit($form, $this->post($form), ['cv' => $this->file(['type' => ''])]);

        /**
         * contenir/storage 0.x falls back to application/octet-stream, 2.x
         * sniffs the content; either way the empty client type is not used.
         */
        static::assertMatchesRegularExpression('#^[a-z]+/[a-z0-9.+-]+$#', $this->storedMimeTypes()[0] ?? '');
    }

    #[Test]
    public function hiddenConditionalUploadIsNotStoredButLaterUploadsAre(): void
    {
        $form = F::form([
            F::field('text', 'mode'),
            F::field('file', 'cv', conditional: [
                'show_when' => ['all' => [['field' => 'mode', 'op' => 'equals', 'value' => 'apply']]],
            ]),
            F::field('file', 'photo'),
        ]);
        $service = $this->acceptingService($this->manager);

        $result = $service->submit($form, $this->post($form, ['mode' => 'ask']), [
            'cv'    => $this->file(),
            'photo' => $this->file(['name' => 'photo.pdf']),
        ]);

        static::assertTrue($result->valid);
        static::assertArrayNotHasKey('cv', $result->values);
        static::assertFalse($this->storage->exists('forms/contact/cv.pdf'));
        static::assertSame('forms/contact/photo.pdf', $result->values['photo']);
    }

    #[Test]
    public function invalidSubmissionStoresNothing(): void
    {
        $form    = F::form([F::field('text', 'name', required: true), F::field('file', 'cv')]);
        $service = $this->acceptingService($this->manager);

        $result = $service->submit($form, $this->post($form, ['name' => '']), ['cv' => $this->file()]);

        static::assertArrayHasKey('name', $result->errors);
        static::assertFalse($this->storage->exists('forms/contact/cv.pdf'));
    }

    /**
     * @param array<string, mixed> $earlier
     */
    #[Test]
    #[DataProvider('precedingUploadProvider')]
    public function laterFileFieldsAreStoredAfterASkippedOne(array $earlier): void
    {
        $form    = F::form([F::field('text', 'name'), F::field('file', 'photo'), F::field('file', 'cv')]);
        $service = $this->acceptingService($this->manager);

        $result = $service->submit($form, $this->post($form), [...$earlier, 'cv' => $this->file()]);

        static::assertSame('forms/contact/cv.pdf', $result->values['cv']);
    }

    #[Test]
    public function missingClientNameAndTypeFallBackToDefaults(): void
    {
        $form    = $this->uploadForm();
        $service = $this->acceptingService($this->manager);

        $result = $service->submit($form, $this->post($form), ['cv' => $this->file(['name' => null, 'type' => ''])]);

        /**
         * The base name is FormBuilder's default; whether an extension is
         * appended for the detected type is up to the storage backend
         * (contenir/storage 0.x leaves it off, 2.x adds it).
         */
        static::assertMatchesRegularExpression('#^forms/contact/upload(\\.[a-z0-9]+)?$#', $result->values['cv']);
    }

    #[Test]
    public function nonArrayUploadIsSkipped(): void
    {
        $form    = $this->uploadForm();
        $service = $this->acceptingService($this->manager);

        $service->submit($form, $this->post($form), ['cv' => 'cv.pdf']);

        static::assertFalse($this->storage->exists('forms/contact/cv.pdf'));
    }

    #[Test]
    public function postedValueForAFileFieldIsIgnored(): void
    {
        $form    = F::form([F::field('text', 'name'), F::field('file', 'cv')]);
        $service = $this->acceptingService($this->manager);

        $result = $service->submit($form, $this->post($form, ['cv' => 'forms/other/secret.pdf']));

        static::assertTrue($result->valid);
        static::assertNull($result->values['cv']);
    }

    #[Test]
    public function realServiceOnlyAcceptsHttpUploads(): void
    {
        $form    = $this->uploadForm();
        $service = new FormSubmissionService($this->builder, $this->manager);

        $service->submit($form, $this->post($form), ['cv' => $this->file()]);

        static::assertFalse($this->storage->exists('forms/contact/cv.pdf'));
    }

    #[Test]
    public function requiredFileFieldAcceptsAnUpload(): void
    {
        $form    = F::form([F::field('file', 'cv', required: true)]);
        $service = $this->acceptingService($this->manager);

        $result = $service->submit($form, $this->post($form), ['cv' => $this->file()]);

        static::assertSame([], $result->errors);
        static::assertSame('forms/contact/cv.pdf', $result->values['cv']);
    }

    #[Test]
    public function requiredFileFieldWithoutAnUploadIsRejected(): void
    {
        $form    = F::form([F::field('file', 'cv', required: true)]);
        $service = $this->acceptingService($this->manager);

        $result = $service->submit($form, $this->post($form));

        static::assertArrayHasKey('cv', $result->errors);
    }

    #[Test]
    #[DataProvider('spamProvider')]
    public function spamSubmissionStoresNothingAndReportsNoFile(string $name, string $honeypot): void
    {
        $form    = F::form([F::field('text', 'name', required: true), F::field('file', 'cv')]);
        $service = $this->acceptingService($this->manager);

        $result = $service->submit(
            $form,
            $this->post($form, ['name' => $name, FormBuilderService::HONEYPOT_NAME => $honeypot]),
            ['cv' => $this->file()],
        );

        static::assertTrue($result->isSpam);
        static::assertNull($result->values['cv']);
        static::assertFalse($this->storage->exists('forms/contact/cv.pdf'));
    }

    #[Test]
    public function storesTheClientMimeType(): void
    {
        $form    = $this->uploadForm();
        $service = $this->acceptingService($this->manager);

        $service->submit($form, $this->post($form), ['cv' => $this->file()]);

        static::assertSame(['application/pdf'], $this->storedMimeTypes());
    }

    #[Test]
    public function storesTheUploadUnderTheFormSlugAndSubmitsItsPath(): void
    {
        $form    = $this->uploadForm();
        $service = $this->acceptingService($this->manager);

        $result = $service->submit($form, $this->post($form), ['cv' => $this->file()]);

        static::assertSame('forms/contact/cv.pdf', $result->values['cv']);
        static::assertTrue($this->storage->exists('forms/contact/cv.pdf'));
    }

    #[Test]
    public function surroundingSlashesInTheSlugAreTrimmed(): void
    {
        $section = F::section('main', [F::field('file', 'cv')]);
        $form    = new FormDefinition(1, '/contact/', 'Contact', sections: [$section]);
        $service = $this->acceptingService($this->manager);

        $result = $service->submit($form, $this->post($form), ['cv' => $this->file()]);

        static::assertSame('forms/contact/cv.pdf', $result->values['cv']);
    }

    /**
     * @param array<string, mixed> $override
     */
    #[Test]
    #[DataProvider('skippedUploadProvider')]
    public function unusableUploadsAreSkipped(array $override): void
    {
        $form    = $this->uploadForm();
        $service = $this->acceptingService($this->manager);

        $result = $service->submit($form, $this->post($form), ['cv' => $this->file($override)]);

        static::assertFalse($this->storage->exists('forms/contact/cv.pdf'));
        static::assertSame([], $result->errors);
    }

    #[Test]
    public function uploadOverTheDefaultLimitIsRejected(): void
    {
        $form    = $this->uploadForm();
        $service = $this->acceptingService($this->manager);
        $handle  = fopen($this->upload, mode: 'w');
        static::assertNotFalse($handle);
        ftruncate($handle, FileUpload::DEFAULT_MAX_SIZE + 1);
        fclose($handle);

        $result = $service->submit($form, $this->post($form), ['cv' => $this->file()]);

        static::assertSame(
            ['cv' => [FileUpload::TOO_LARGE => 'The file is larger than the 10 MB limit.']],
            $result->errors,
        );
        static::assertFalse($this->storage->exists('forms/contact/cv.pdf'));
    }

    #[Test]
    public function uploadsAreSkippedWithoutADefaultStorageProfile(): void
    {
        $form    = $this->uploadForm();
        $manager = new StorageManager();
        $manager->register('cdn', $this->storage);
        $service = $this->acceptingService($manager);

        $service->submit($form, $this->post($form), ['cv' => $this->file()]);

        static::assertFalse($this->storage->exists('forms/contact/cv.pdf'));
    }

    #[Test]
    public function uploadsAreSkippedWithoutAStorageManager(): void
    {
        $form    = $this->uploadForm();
        $service = $this->acceptingService(null);

        $result = $service->submit($form, $this->post($form), ['cv' => $this->file()]);

        static::assertTrue($result->valid);
        static::assertNull($result->values['cv']);
        static::assertFalse($this->storage->exists('forms/contact/cv.pdf'));
    }

    #[Test]
    public function uploadsForNonFileFieldsAreIgnored(): void
    {
        $form    = F::form([F::field('text', 'cv')]);
        $service = $this->acceptingService($this->manager);

        $result = $service->submit($form, $this->post($form, ['cv' => 'typed']), ['cv' => $this->file()]);

        static::assertSame('typed', $result->values['cv']);
    }

    #[Override]
    protected function setUp(): void
    {
        $this->setUpInMemorySession();
        $this->setUpTemporaryDirectory();
        $this->builder = new FormBuilderService(new FieldTypeRegistry(), new ValidatorFactory());
        $this->storage = new InMemoryStorage();
        $this->manager = new StorageManager();
        $this->manager->register(StorageManager::DEFAULT_PROFILE, $this->storage);
        $this->upload = "{$this->tmpDir}/php-upload";
        file_put_contents($this->upload, data: '%PDF-1.4');
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
        $this->tearDownInMemorySession();
    }

    /**
     * Treats any existing file as an HTTP upload, so the upload path runs
     * outside a real request.
     */
    private function acceptingService(?StorageManager $manager): FormSubmissionService
    {
        return new FormSubmissionService($this->builder, $manager, is_file(...));
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function file(array $override = []): array
    {
        return [
            ...[
                'name'     => 'cv.pdf',
                'type'     => 'application/pdf',
                'tmp_name' => $this->upload,
                'error'    => UPLOAD_ERR_OK,
                'size'     => 8,
            ],
            ...$override,
        ];
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function post(FormDefinition $form, array $values = []): array
    {
        $csrf = $this->builder->build($form)->get(FormBuilderService::CSRF_NAME)->getValue();

        return [...$values, FormBuilderService::CSRF_NAME => $csrf];
    }

    /**
     * @return list<string>
     */
    private function storedMimeTypes(): array
    {
        $mimes = [];
        foreach ($this->storage->list('forms/contact') as $entry) {
            $mimes[] = $entry->mime;
        }

        return $mimes;
    }

    private function uploadForm(): FormDefinition
    {
        return F::form([F::field('file', 'cv')]);
    }
}
