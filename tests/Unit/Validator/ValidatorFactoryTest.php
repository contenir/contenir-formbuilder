<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\Validator;

use Contenir\FormBuilder\Definition\ValidatorDefinition;
use Contenir\FormBuilder\Validator\ValidatorFactory;
use InvalidArgumentException;
use Laminas\Validator\Between;
use Laminas\Validator\Callback;
use Laminas\Validator\EmailAddress;
use Laminas\Validator\Regex;
use Laminas\Validator\StringLength;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_values;

#[Group('unit')]
final class ValidatorFactoryTest extends TestCase
{
    /** @return array<string, array{string, class-string}> */
    public static function knownTypeProvider(): array
    {
        return [
            'string_length' => [ValidatorFactory::TYPE_STRING_LENGTH, StringLength::class],
            'between'       => [ValidatorFactory::TYPE_BETWEEN, Between::class],
            'email'         => [ValidatorFactory::TYPE_EMAIL, EmailAddress::class],
            'url'           => [ValidatorFactory::TYPE_URL, Callback::class],
            'regex'         => [ValidatorFactory::TYPE_REGEX, Regex::class],
        ];
    }

    /**
     * @return array<string, array{string, array<string, mixed>, mixed, bool}>
     */
    public static function validationProvider(): array
    {
        return [
            'url accepts https'                 => ['url', [], 'https://example.com/path?q=1', true],
            'url accepts http'                  => ['url', [], 'http://example.com', true],
            'url rejects bare hostname'         => ['url', [], 'example.com', false],
            'url rejects other schemes'         => ['url', [], 'ftp://example.com', false],
            'url rejects non-strings'           => ['url', [], 42, false],
            'url scheme is case-insensitive'    => ['url', [], 'HTTPS://example.com', true],
            'url must start with the scheme'    => ['url', [], 'ftp://example.com/?to=http://x', false],
            'length within min and max'         => ['string_length', ['min' => '2', 'max' => '4'], 'abc', true],
            'length above max'                  => ['string_length', ['min' => 2, 'max' => 4], 'abcde', false],
            'length below min'                  => ['string_length', ['min' => '2', 'max' => '4'], 'a', false],
            'length without options'            => ['string_length', [], 'any length at all', true],
            'length allows empty without min'   => ['string_length', [], '', true],
            'non-numeric max is ignored'        => ['string_length', ['max' => 'many'], 'abcdef', true],
            'between inclusive by default'      => ['between', ['min' => 1, 'max' => 5], 5, true],
            'between exclusive from checkbox 0' => ['between', ['min' => 1, 'max' => 5, 'inclusive' => '0'], 5, false],
            'between open-ended maximum'        => ['between', ['min' => 1], 1000, true],
            'between below min'                 => ['between', ['min' => 3, 'max' => 5], 2, false],
            'between minimum defaults to zero'  => ['between', ['max' => 5], 0, true],
            'between rejects below zero'        => ['between', ['max' => 5], -1, false],
            'regex pattern applied'             => ['regex', ['pattern' => '/^[0-9]+$/'], 'abc', false],
            'regex without pattern matches all' => ['regex', ['pattern' => ''], 'abc', true],
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[Test]
    #[DataProvider('validationProvider')]
    public function configuresTheValidatorFromItsOptions(string $type, array $options, mixed $value, bool $valid): void
    {
        $validator = (new ValidatorFactory())->create(new ValidatorDefinition($type, $options));

        static::assertNotNull($validator);
        static::assertSame($valid, $validator->isValid($value));
    }

    #[Test]
    public function confirmReturnsNullBecauseItIsAttachedAsCrossFieldRule(): void
    {
        $factory = new ValidatorFactory();
        static::assertNull($factory->create(new ValidatorDefinition(ValidatorFactory::TYPE_CONFIRM)));
    }

    #[Test]
    public function customMessageIsApplied(): void
    {
        $factory   = new ValidatorFactory();
        $validator = $factory->create(new ValidatorDefinition(
            ValidatorFactory::TYPE_EMAIL,
            [],
            'Custom email error',
        ));

        static::assertNotNull($validator);
        $validator->isValid('not-an-email');
        static::assertContains('Custom email error', $validator->getMessages());
    }

    #[Test]
    public function emptyCustomMessageKeepsTheDefault(): void
    {
        $validator = (new ValidatorFactory())->create(new ValidatorDefinition(ValidatorFactory::TYPE_URL, message: ''));

        static::assertNotNull($validator);
        $validator->isValid('x');
        static::assertSame(['The input is not a valid http or https URL'], array_values($validator->getMessages()));
    }

    /** @param class-string $expectedClass */
    #[Test]
    #[DataProvider('knownTypeProvider')]
    public function knownTypesProduceExpectedValidators(string $type, string $expectedClass): void
    {
        $factory   = new ValidatorFactory();
        $validator = $factory->create(new ValidatorDefinition($type, ['min' => 0, 'max' => 5, 'pattern' => '/.*/']));

        static::assertInstanceOf($expectedClass, $validator);
    }

    #[Test]
    public function requiredReturnsNullBecauseItIsHandledOnTheInputFilter(): void
    {
        $factory = new ValidatorFactory();
        static::assertNull($factory->create(new ValidatorDefinition(ValidatorFactory::TYPE_REQUIRED)));
    }

    #[Test]
    public function unknownTypeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown validator type "not_a_real_validator"');
        (new ValidatorFactory())->create(new ValidatorDefinition('not_a_real_validator'));
    }

    #[Test]
    public function urlValidatorExplainsTheFailure(): void
    {
        $validator = (new ValidatorFactory())->create(new ValidatorDefinition(ValidatorFactory::TYPE_URL));

        static::assertNotNull($validator);
        $validator->isValid('example.com');
        static::assertSame(['The input is not a valid http or https URL'], array_values($validator->getMessages()));
    }

    #[Test]
    public function vocabularyFlagsTheTypesThatNeedOptions(): void
    {
        static::assertSame(
            [
                'string_length' => true,
                'between'       => true,
                'email'         => false,
                'url'           => false,
                'regex'         => true,
                'confirm'       => true,
            ],
            array_column(ValidatorFactory::vocabulary(), 'requires_options', 'type'),
        );
    }

    #[Test]
    public function vocabularyOmitsRequiredAndListsEveryOtherType(): void
    {
        static::assertSame(
            ['string_length', 'between', 'email', 'url', 'regex', 'confirm'],
            array_column(ValidatorFactory::vocabulary(), 'type'),
        );
    }
}
