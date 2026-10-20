<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Redaction;

use Contenir\FormBuilder\Definition\FormDefinition;
use Contenir\FormBuilder\Redaction\StorageEntryFiles;
use Contenir\FormBuilder\Tests\TestAsset\Factory\FormDefinitionFactory as F;
use Contenir\Storage\Exception\NotFoundException;
use Contenir\Storage\StorageInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[Group('unit')]
final class StorageEntryFilesTest extends TestCase
{
    private static function form(): FormDefinition
    {
        return F::form([F::field('text', 'name'), F::field('file', 'cv'), F::field('file', 'photos')]);
    }

    #[Test]
    public function countsAMissingFileAsDeleted(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects($this->exactly(2))
            ->method('delete')
            ->willThrowException(NotFoundException::forPath('forms/contact/a.pdf'));

        static::assertTrue((new StorageEntryFiles($storage))->delete(self::form(), 7, [
            ['field_name' => 'cv', 'value_text' => 'forms/contact/a.pdf'],
            ['field_name' => 'photos', 'value_json' => '["forms/contact/b.jpg"]'],
        ]));
    }

    #[Test]
    public function deletesEveryPathTheUploadFieldsHold(): void
    {
        $deleted = [];
        $storage = static::createStub(StorageInterface::class);
        $storage->method('delete')
            ->willReturnCallback(static function (string $path) use (&$deleted): void {
                $deleted[] = $path;
            });

        $gone = (new StorageEntryFiles($storage))->delete(self::form(), 7, [
            ['field_name' => 'cv', 'value_text' => '/forms/contact/cv.pdf', 'value_json' => null],
            [
                'field_name' => 'photos',
                'value_text' => null,
                'value_json' => '[5,"forms/contact/a.jpg",""," /forms/contact/b.jpg"]',
            ],
            ['field_name' => 'name', 'value_text' => 'forms/contact/not-a-file.pdf', 'value_json' => null],
            ['field_name' => 'cv', 'value_text' => ' ', 'value_json' => null],
            ['field_name' => 'photos', 'value_text' => null, 'value_json' => null],
        ]);

        static::assertSame(
            [true, ['forms/contact/cv.pdf', 'forms/contact/a.jpg', 'forms/contact/b.jpg']],
            [$gone, $deleted],
        );
    }

    #[Test]
    public function failsQuietlyWithoutALogger(): void
    {
        $storage = static::createStub(StorageInterface::class);
        $storage->method('delete')->willThrowException(new RuntimeException('read-only'));

        static::assertFalse((new StorageEntryFiles($storage))->delete(self::form(), 7, [
            ['field_name' => 'cv', 'value_text' => 'forms/contact/a.pdf'],
        ]));
    }

    #[Test]
    public function stopsAndLogsAtAFileItCannotDelete(): void
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->expects($this->once())->method('delete')->willThrowException(new RuntimeException('read-only'));
        $log = $this->createMock(LoggerInterface::class);
        $log->expects($this->once())
            ->method('warning')
            ->with(
                'Entry 7 of form "contact" was not redacted: its file forms/contact/a.pdf could not be deleted: read-only',
            );

        static::assertFalse((new StorageEntryFiles($storage, $log))->delete(self::form(), 7, [
            ['field_name' => 'cv', 'value_text' => 'forms/contact/a.pdf'],
            ['field_name' => 'photos', 'value_json' => '["forms/contact/b.jpg"]'],
        ]));
    }
}
