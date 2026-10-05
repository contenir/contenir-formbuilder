<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Integration\Render;

use Contenir\FormBuilder\FieldType\FieldTypeRegistry;
use Contenir\FormBuilder\Render\FormMarkup;
use Contenir\FormBuilder\Service\FormBuilderService;
use Contenir\FormBuilder\Tests\TestAsset\Factory\FormDefinitionFactory as F;
use Contenir\FormBuilder\Tests\Trait\InMemorySessionTrait;
use Contenir\FormBuilder\Validator\ValidatorFactory;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Renders forms produced by the builder, including the session-backed CSRF token.
 */
#[Group('integration')]
#[Group('render')]
final class FormMarkupTest extends TestCase
{
    use InMemorySessionTrait;

    #[Test]
    public function rendersBuiltFormWithCsrfHoneypotAndSubmit(): void
    {
        $definition = F::form([F::field('text', 'name', label: 'Name')], submitLabel: 'Send');
        $form       = (new FormBuilderService(new FieldTypeRegistry(), new ValidatorFactory()))->build($definition);
        $token      = $form->get(FormBuilderService::CSRF_NAME)->getValue();

        $html = (new FormMarkup())->render($definition, $form);

        static::assertStringContainsString(
            '<div class="formbuilder__actions formbuilder__actions--left">'
                . "<input type=\"hidden\" name=\"_csrf\" value=\"{$token}\" id=\"_csrf\">"
                . '<div class="formbuilder__honeypot" aria-hidden="true">'
                . '<input type="text" name="hid" autocomplete="off" tabindex="-1" aria-hidden="true" id="hid"></div>'
                . '<input type="submit" name="_submit" class="btn btn--primary" value="Send"></div></form>',
            $html,
        );
    }

    #[Override]
    protected function setUp(): void
    {
        $this->setUpInMemorySession();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->tearDownInMemorySession();
    }
}
