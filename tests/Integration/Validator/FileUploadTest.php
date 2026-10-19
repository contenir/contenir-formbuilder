<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Integration\Validator;

use Contenir\FormBuilder\Tests\Trait\TemporaryDirectoryTrait;
use Contenir\FormBuilder\Validator\FileUpload;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function fclose;
use function file_put_contents;
use function fopen;
use function ftruncate;

#[Group('integration')]
#[Group('validator')]
final class FileUploadTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private const string PDF = '%PDF-1.4';

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function allowedExtensionProvider(): array
    {
        return [
            'listed extension'           => [['pdf', 'docx'], 'cv.docx'],
            'listed extension, any case' => [['pdf'], 'CV.PDF'],
            'no list'                    => [[], 'cv.exe'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function disallowedExtensionProvider(): array
    {
        return [
            'unlisted extension'         => ['cv.exe'],
            'no extension'               => ['cv'],
            'extension only in the name' => ['cv.pdf.exe'],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidValueProvider(): array
    {
        return [
            'not an array'                  => ['forms/contact/cv.pdf'],
            'no temporary path'             => [['name' => 'cv.pdf']],
            'non-string temporary path'     => [['name' => 'cv.pdf', 'tmp_name' => ['x']]],
            'temporary file does not exist' => [['name' => 'cv.pdf', 'tmp_name' => '/nonexistent/upload']],
        ];
    }

    /**
     * @return array<string, array{list<string>, string, bool}>
     */
    public static function mimeTypeProvider(): array
    {
        return [
            'detected type listed'           => [['image/png', 'application/pdf'], 'image/png', true],
            'detected type listed, any case' => [['Application/PDF'], 'image/png', true],
            'only the client type listed'    => [['image/png'], 'image/png', false],
            'no list'                        => [[], 'image/png', true],
        ];
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function sizeLimitProvider(): array
    {
        return [
            'whole megabytes'       => [1_048_576, '1 MB'],
            'fraction of megabytes' => [1_572_864, '1.5 MB'],
            'rounded megabytes'     => [1_100_000, '1 MB'],
            'just under a megabyte' => [1_048_575, '1024 KB'],
            'fraction of kilobytes' => [1536, '1.5 KB'],
            'rounded kilobytes'     => [1100, '1.1 KB'],
        ];
    }

    #[Test]
    public function acceptsAFileOfExactlyTheMaximumSize(): void
    {
        static::assertTrue((new FileUpload(maxSize: 100))->isValid($this->uploadOfSize(100)));
    }

    /**
     * @param list<string> $extensions
     */
    #[Test]
    #[DataProvider('allowedExtensionProvider')]
    public function acceptsAnAllowedClientFileNameExtension(array $extensions, string $name): void
    {
        static::assertTrue((new FileUpload(extensions: $extensions))->isValid($this->upload(name: $name)));
    }

    /**
     * @param list<string> $mimeTypes
     */
    #[Test]
    #[DataProvider('mimeTypeProvider')]
    public function checksTheMimeTypeDetectedFromTheContents(array $mimeTypes, string $clientType, bool $valid): void
    {
        $validator = new FileUpload(mimeTypes: $mimeTypes);

        static::assertSame($valid, $validator->isValid($this->upload(type: $clientType)));
    }

    #[Test]
    public function describesTheAllowedExtensionsAndMimeTypes(): void
    {
        $validator = new FileUpload(
            extensions: ['.PDF', 'docx'],
            mimeTypes: ['image/png', 'image/jpeg'],
        );

        $validator->isValid($this->upload(name: 'cv.exe'));

        static::assertSame(
            [
                FileUpload::EXTENSION => 'Only these file types can be uploaded: .pdf, .docx.',
                FileUpload::MIME_TYPE => "The file's contents are not an allowed type (image/png, image/jpeg).",
            ],
            $validator->getMessages(),
        );
    }

    #[Test]
    public function messagesAreClearedForTheNextValue(): void
    {
        $validator = new FileUpload(maxSize: 100);
        $validator->isValid($this->uploadOfSize(101));

        static::assertTrue($validator->isValid($this->uploadOfSize(100)));
        static::assertSame([], $validator->getMessages());
    }

    #[Test]
    public function missingClientNameHasNoExtension(): void
    {
        $validator = new FileUpload(extensions: ['pdf']);

        static::assertFalse($validator->isValid(['tmp_name' => $this->upload()['tmp_name']]));
        static::assertArrayHasKey(FileUpload::EXTENSION, $validator->getMessages());
    }

    #[Test]
    #[DataProvider('disallowedExtensionProvider')]
    public function rejectsADisallowedClientFileNameExtension(string $name): void
    {
        $validator = new FileUpload(extensions: ['pdf']);

        static::assertFalse($validator->isValid($this->upload(name: $name)));
        static::assertSame(
            [FileUpload::EXTENSION => 'Only these file types can be uploaded: .pdf.'],
            $validator->getMessages(),
        );
    }

    #[Test]
    public function rejectsAFileOverTheDefaultTenMegabytes(): void
    {
        $validator = new FileUpload();

        static::assertTrue($validator->isValid($this->uploadOfSize(FileUpload::DEFAULT_MAX_SIZE)));
        static::assertFalse($validator->isValid($this->uploadOfSize(FileUpload::DEFAULT_MAX_SIZE + 1)));
        static::assertSame(
            [FileUpload::TOO_LARGE => 'The file is larger than the 10 MB limit.'],
            $validator->getMessages(),
        );
    }

    #[Test]
    #[DataProvider('sizeLimitProvider')]
    public function rejectsAFileOverTheMaximumSizeAndNamesTheLimit(int $maxSize, string $limit): void
    {
        $validator = new FileUpload(maxSize: $maxSize);

        static::assertFalse($validator->isValid($this->uploadOfSize($maxSize + 1)));
        static::assertSame(
            [FileUpload::TOO_LARGE => "The file is larger than the {$limit} limit."],
            $validator->getMessages(),
        );
    }

    #[Test]
    #[DataProvider('invalidValueProvider')]
    public function rejectsAValueThatIsNotAReadableUpload(mixed $value): void
    {
        $validator = new FileUpload();

        static::assertFalse($validator->isValid($value));
        static::assertSame(
            [FileUpload::INVALID => 'The file could not be read. Please upload it again.'],
            $validator->getMessages(),
        );
    }

    #[Test]
    public function reportsEveryFailedCheck(): void
    {
        $validator = new FileUpload(
            maxSize: 4,
            extensions: ['docx'],
            mimeTypes: ['image/png'],
        );

        static::assertFalse($validator->isValid($this->upload()));
        static::assertSame(
            [FileUpload::TOO_LARGE, FileUpload::EXTENSION, FileUpload::MIME_TYPE],
            array_keys($validator->getMessages()),
        );
    }

    #[Override]
    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    /**
     * A `$_FILES`-style entry for a small PDF.
     *
     * @return array{name: string, type: string, tmp_name: string}
     */
    private function upload(string $name = 'cv.pdf', string $type = 'application/pdf'): array
    {
        $path = "{$this->tmpDir}/upload.pdf";
        file_put_contents($path, self::PDF);

        return ['name' => $name, 'type' => $type, 'tmp_name' => $path];
    }

    /**
     * A `$_FILES`-style entry for an empty (sparse) file of $size bytes.
     *
     * @return array{name: string, type: string, tmp_name: string}
     */
    private function uploadOfSize(int $size): array
    {
        $path   = "{$this->tmpDir}/upload-{$size}";
        $handle = fopen($path, mode: 'w');
        static::assertNotFalse($handle);
        ftruncate($handle, $size);
        fclose($handle);

        return ['name' => 'cv.pdf', 'type' => 'application/pdf', 'tmp_name' => $path];
    }
}
