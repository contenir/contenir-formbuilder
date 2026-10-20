<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Redaction;

use Contenir\FormBuilder\Redaction\NoFileStorage;
use Contenir\FormBuilder\Tests\TestAsset\Factory\FormDefinitionFactory as F;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[Group('unit')]
final class NoFileStorageTest extends TestCase
{
    #[Test]
    public function keepsAndLogsAnEntryWithFiles(): void
    {
        $log = $this->createMock(LoggerInterface::class);
        $log->expects($this->once())
            ->method('warning')
            ->with('Entry 7 of form "contact" was not redacted: it has files and no file storage is configured');

        static::assertFalse((new NoFileStorage($log))->delete(
            F::form([F::field('file', 'cv')]),
            7,
            [['field_name' => 'cv', 'value_text' => 'forms/contact/cv.pdf']],
        ));
    }

    #[Test]
    public function keepsAnEntryWithFilesQuietlyWithoutALogger(): void
    {
        static::assertFalse((new NoFileStorage())->delete(
            F::form([F::field('file', 'cv')]),
            7,
            [['field_name' => 'cv', 'value_text' => 'forms/contact/cv.pdf']],
        ));
    }

    #[Test]
    public function letsAnEntryWithNoFilesBeRedacted(): void
    {
        $log = $this->createMock(LoggerInterface::class);
        $log->expects($this->never())->method('warning');

        static::assertTrue((new NoFileStorage($log))->delete(
            F::form([F::field('text', 'name'), F::field('file', 'cv')]),
            7,
            [['field_name' => 'name', 'value_text' => 'forms/x.pdf'], ['field_name' => 'cv', 'value_text' => null]],
        ));
    }
}
