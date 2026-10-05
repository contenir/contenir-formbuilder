<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Integration\Service;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\FieldType\FieldTypeRegistry;
use Contenir\FormBuilder\Service\FormBuilderService;
use Contenir\FormBuilder\Service\FormSubmissionService;
use Contenir\FormBuilder\Tests\TestAsset\Factory\FormDefinitionFactory as F;
use Contenir\FormBuilder\Tests\Trait\InMemorySessionTrait;
use Contenir\FormBuilder\Tests\Trait\TemporaryDirectoryTrait;
use Contenir\FormBuilder\Validator\ValidatorFactory;
use Contenir\Storage\Adapter\InMemoryStorage;
use Contenir\Storage\StorageManager;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
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
    public function realServiceOnlyAcceptsHttpUploads(): void
    {
        $form    = $this->uploadForm();
        $service = new FormSubmissionService($this->builder, $this->manager);

        $service->submit($form, $this->post($form), ['cv' => $this->file()]);

        static::assertFalse($this->storage->exists('forms/contact/cv.pdf'));
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

        $service->submit($form, $this->post($form), ['cv' => $this->file()]);

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

    private function uploadForm(): FormDefinition
    {
        return F::form([F::field('file', 'cv')]);
    }
}
