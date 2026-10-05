<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Service;

use Contenir\FormBuilder\Service\SubmissionResult;
use Laminas\Form\Form;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class SubmissionResultTest extends TestCase
{
    #[Test]
    public function resultIsNotSpamUnlessFlagged(): void
    {
        $result = new SubmissionResult(
            valid: true,
            form: new Form(),
        );

        static::assertSame([[], [], false, null], [
            $result->values,
            $result->errors,
            $result->isSpam,
            $result->entryId,
        ]);
    }
}
