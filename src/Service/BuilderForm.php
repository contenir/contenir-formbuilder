<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Service;

use ArrayObject;
use Laminas\Form\Form;
use Override;
use SplObserver;
use SplSubject;

use function array_search;
use function array_values;

/**
 * Laminas\Form subclass exposing a public {@see $registry} property and acting
 * as the SplSubject passed to registrar observers.
 *
 * The {@see FormSubmissionService} piggybacks submission context onto the form
 * so registrars don't need to receive it as separate arguments. Implements
 * SplSubject so the form can be passed directly to {@see \SplObserver::update()}
 * — the {@see FormSubmissionService} owns observer dispatch, so the methods
 * here are sufficient for the type contract.
 *
 * @api
 *
 * @extends Form<array<string, mixed>>
 */
final class BuilderForm extends Form implements SplSubject
{
    /** @var ArrayObject<string, mixed>|null */
    public ?ArrayObject $registry = null;

    /** @var list<SplObserver> */
    private array $observers = [];

    #[Override]
    public function attach(SplObserver $observer): void
    {
        $this->observers[] = $observer;
    }

    #[Override]
    public function detach(SplObserver $observer): void
    {
        $index = array_search($observer, $this->observers, strict: true);
        if (false === $index) {
            return;
        }

        unset($this->observers[$index]);
        $this->observers = array_values($this->observers);
    }

    #[Override]
    public function notify(): void
    {
        foreach ($this->observers as $observer) {
            $observer->update($this);
        }
    }
}
