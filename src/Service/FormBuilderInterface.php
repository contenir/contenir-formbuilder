<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Service;

use Contenir\FormBuilder\Definition\FormDefinition;
use Laminas\Form\FormInterface;

/**
 * Builds a validation-ready Laminas form from a definition. Implement it to
 * customise form construction; {@see FormSubmissionService} depends on this
 * interface, not on {@see FormBuilderService}.
 *
 * Observers are only notified when the built form is a {@see BuilderForm}.
 *
 * @api
 */
interface FormBuilderInterface
{
    public function build(FormDefinition $form): FormInterface;
}
