<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Service;

use Contenir\FormBuilder\Service\BuilderForm;
use Contenir\FormBuilder\Tests\TestAsset\Observer\RecordingObserver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class BuilderFormTest extends TestCase
{
    #[Test]
    public function detachedObserversAreNotNotified(): void
    {
        $form     = new BuilderForm();
        $kept     = new RecordingObserver();
        $detached = new RecordingObserver();
        $form->attach($detached);
        $form->attach($kept);

        $form->detach($detached);
        $form->notify();

        static::assertSame([[], [$form]], [$detached->subjects, $kept->subjects]);
    }

    #[Test]
    public function detachingAnUnknownObserverChangesNothing(): void
    {
        $form     = new BuilderForm();
        $observer = new RecordingObserver();
        $form->attach($observer);

        $form->detach(new RecordingObserver());
        $form->notify();

        static::assertSame([$form], $observer->subjects);
    }

    #[Test]
    public function notifiesEveryAttachedObserverWithItself(): void
    {
        $form   = new BuilderForm();
        $first  = new RecordingObserver();
        $second = new RecordingObserver();
        $form->attach($first);
        $form->attach($second);

        $form->notify();

        static::assertSame([[$form], [$form]], [$first->subjects, $second->subjects]);
    }
}
