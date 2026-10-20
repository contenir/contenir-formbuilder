<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Redaction;

use Contenir\FormBuilder\Redaction\RedactionReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class RedactionReportTest extends TestCase
{
    /**
     * @return array<string, array{RedactionReport, string}>
     */
    public static function summaryProvider(): array
    {
        return [
            'nothing'           => [new RedactionReport(), 'No entries are past their form\'s retention period.'],
            'one of each'       => [new RedactionReport(1, 1), 'Redacted 1 entry on 1 form.'],
            'several'           => [new RedactionReport(5, 2), 'Redacted 5 entries on 2 forms.'],
            'one kept'          => [
                new RedactionReport(2, 1, 1),
                'Redacted 2 entries on 1 form. 1 entry was kept because its files could not be deleted; '
                    . 'it will be tried again next time.',
            ],
            'several kept only' => [
                new RedactionReport(0, 0, 3),
                'No entries are past their form\'s retention period. 3 entries were kept because their files '
                    . 'could not be deleted; they will be tried again next time.',
            ],
        ];
    }

    #[Test]
    public function addsUpEachFormsOutcome(): void
    {
        $report = (new RedactionReport())->add(3, 0)->add(0, 2)->add(1, 1);

        static::assertSame([4, 2, 3], [$report->entries, $report->forms, $report->failed]);
    }

    #[Test]
    #[DataProvider('summaryProvider')]
    public function summarisesTheRun(RedactionReport $report, string $summary): void
    {
        static::assertSame($summary, $report->summary());
    }
}
