<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Validator;

use Contenir\FormBuilder\Validator\FileUpload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Configuration only; the checks against real files are in the integration
 * suite.
 */
#[Group('unit')]
#[Group('validator')]
final class FileUploadTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>, FileUpload}>
     */
    public static function optionsProvider(): array
    {
        return [
            'no options'                   => [[], new FileUpload()],
            'integer size'                 => [['max_size' => 2048], new FileUpload(maxSize: 2048)],
            'numeric string size'          => [['max_size' => '2048'], new FileUpload(maxSize: 2048)],
            'smallest size'                => [['max_size' => 1], new FileUpload(maxSize: 1)],
            'zero size'                    => [['max_size' => 0], new FileUpload()],
            'negative size'                => [['max_size' => -5], new FileUpload()],
            'fractional size'              => [['max_size' => '1.5'], new FileUpload()],
            'non-numeric size'             => [['max_size' => 'big'], new FileUpload()],
            'extension list'               => [
                ['extensions' => ['pdf', 'docx']],
                new FileUpload(extensions: [
                    'pdf',
                    'docx',
                ]),
            ],
            'comma-separated extensions'   => [
                ['extensions' => 'pdf, docx'],
                new FileUpload(extensions: [
                    'pdf',
                    'docx',
                ]),
            ],
            'blank and non-string entries' => [
                ['extensions' => ['pdf', ' ', '', 3, ['x']]],
                new FileUpload(
                    extensions: ['pdf'],
                ),
            ],
            'blank comma-separated entry'  => [['extensions' => 'pdf,,'], new FileUpload(extensions: ['pdf'])],
            'non-list extensions'          => [['extensions' => 5], new FileUpload()],
            'MIME type list'               => [
                ['mime_types' => ['application/pdf']],
                new FileUpload(mimeTypes: [
                    'application/pdf',
                ]),
            ],
            'comma-separated MIME types'   => [
                ['mime_types' => 'application/pdf,image/png'],
                new FileUpload(
                    mimeTypes: ['application/pdf', 'image/png'],
                ),
            ],
        ];
    }

    #[Test]
    public function defaultMaximumSizeIsTenMegabytes(): void
    {
        static::assertSame(10 * 1024 * 1024, FileUpload::DEFAULT_MAX_SIZE);
    }

    #[Test]
    public function extensionsAndMimeTypesAreNormalised(): void
    {
        static::assertEquals(
            new FileUpload(
                extensions: ['pdf', 'docx'],
                mimeTypes: ['application/pdf'],
            ),
            new FileUpload(
                extensions: ['.PDF', ' docx '],
                mimeTypes: [' Application/PDF '],
            ),
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    #[Test]
    #[DataProvider('optionsProvider')]
    public function fieldOptionsConfigureTheCheck(array $options, FileUpload $expected): void
    {
        static::assertEquals($expected, FileUpload::fromOptions($options));
    }

    #[Test]
    public function hasNoMessagesBeforeValidating(): void
    {
        static::assertSame([], (new FileUpload())->getMessages());
    }

    #[Test]
    public function nonArrayValueCannotBeRead(): void
    {
        $validator = new FileUpload();

        static::assertFalse($validator->isValid('forms/contact/cv.pdf'));
        static::assertSame(
            [FileUpload::INVALID => 'The file could not be read. Please upload it again.'],
            $validator->getMessages(),
        );
    }
}
