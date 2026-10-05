<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Integration\Service;

use Contenir\FormBuilder\FieldType\FieldTypeRegistry;
use Contenir\FormBuilder\Service\FormBuilderService;
use Contenir\FormBuilder\Tests\TestAsset\Factory\FormDefinitionFactory as F;
use Contenir\FormBuilder\Tests\Trait\InMemorySessionTrait;
use Contenir\FormBuilder\Validator\ValidatorFactory;
use Laminas\Form\FormInterface;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;

use function array_keys;
use function is_string;

/**
 * Validates submissions through a real built form, with the CSRF token kept
 * in an in-memory session.
 */
#[Group('integration')]
#[Group('service')]
final class CsrfProtectionTest extends TestCase
{
    use InMemorySessionTrait;

    private FormBuilderService $builder;

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function rejectedTokenProvider(): array
    {
        return [
            'no token'        => [[]],
            'empty token'     => [[FormBuilderService::CSRF_NAME => '']],
            'whitespace only' => [[FormBuilderService::CSRF_NAME => '   ']],
            'wrong token'     => [[FormBuilderService::CSRF_NAME => 'not-the-token']],
        ];
    }

    #[Test]
    public function acceptsTheIssuedToken(): void
    {
        static::assertTrue($this->submitWithToken($this->issueToken())->isValid());
    }

    #[Test]
    public function acceptsTheIssuedTokenWithSurroundingWhitespace(): void
    {
        static::assertTrue($this->submitWithToken(" {$this->issueToken()} ")->isValid());
    }

    #[Test]
    public function rejectsANonStringToken(): void
    {
        $this->issueToken();
        $form = $this->builder->build(F::form());
        $form->setData([FormBuilderService::CSRF_NAME => ['x'], '_submit' => 'Send']);

        static::assertFalse($form->isValid());
    }

    /**
     * @param array<string, mixed> $csrf
     */
    #[Test]
    #[DataProvider('rejectedTokenProvider')]
    public function rejectsASubmissionWithoutTheIssuedToken(array $csrf): void
    {
        $this->issueToken();
        $form = $this->builder->build(F::form());
        $form->setData([...$csrf, '_submit' => 'Send']);

        static::assertFalse($form->isValid());
        static::assertSame([FormBuilderService::CSRF_NAME], array_keys($form->getMessages()));
    }

    #[Test]
    public function rejectsATokenIssuedInAnotherSession(): void
    {
        $token = $this->issueToken();
        $this->tearDownInMemorySession();
        $this->setUpInMemorySession();

        static::assertFalse($this->submitWithToken($token)->isValid());
    }

    #[Override]
    protected function setUp(): void
    {
        $this->setUpInMemorySession();
        $this->builder = new FormBuilderService(new FieldTypeRegistry(), new ValidatorFactory());
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->tearDownInMemorySession();
    }

    /**
     * Renders a form once, as the page that carries the token would.
     */
    private function issueToken(): string
    {
        $token = $this->builder->build(F::form())->get(FormBuilderService::CSRF_NAME)->getValue();
        static::assertTrue(is_string($token) && '' !== $token);

        return $token;
    }

    private function submitWithToken(#[SensitiveParameter] string $token): FormInterface
    {
        $form = $this->builder->build(F::form());
        $form->setData([FormBuilderService::CSRF_NAME => $token, '_submit' => 'Send']);

        return $form;
    }
}
