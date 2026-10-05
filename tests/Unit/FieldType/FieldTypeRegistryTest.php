<?php

declare(strict_types=1);

namespace Contenir\FormBuilder\Tests\Unit\FieldType;

use Contenir\FormBuilder\Definition\FieldDefinition;
use Contenir\FormBuilder\FieldType\AbstractFieldType;
use Contenir\FormBuilder\FieldType\FieldTypeInterface;
use Contenir\FormBuilder\FieldType\FieldTypeRegistry;
use Contenir\FormBuilder\FieldType\TextField;
use Laminas\Form\Element\Text;
use Laminas\Form\ElementInterface;
use OutOfBoundsException;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_diff;
use function array_map;
use function array_values;

#[Group('unit')]
final class FieldTypeRegistryTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function coreTypeProvider(): array
    {
        $keys = [
            'text',
            'textarea',
            'email',
            'url',
            'tel',
            'number',
            'date',
            'datetime',
            'time',
            'select',
            'multiselect',
            'radio',
            'checkbox',
            'multicheckbox',
            'file',
            'hidden',
            'content',
        ];

        $cases = [];
        foreach ($keys as $key) {
            $cases[$key] = [$key];
        }

        return $cases;
    }

    private static function customType(string $key, bool $userSelectable): FieldTypeInterface
    {
        return new class($key, $userSelectable) extends AbstractFieldType {
            public function __construct(
                private readonly string $typeKey,
                private readonly bool $selectable,
            ) {}

            #[Override]
            public function isUserSelectable(): bool
            {
                return $this->selectable;
            }

            #[Override]
            public function key(): string
            {
                return $this->typeKey;
            }

            #[Override]
            public function label(): string
            {
                return $this->typeKey;
            }

            #[Override]
            protected function createElement(FieldDefinition $field): ElementInterface
            {
                return new Text($field->name, ['label' => 'ignored']);
            }
        };
    }

    #[Test]
    public function defaultTypesAreFreshInstances(): void
    {
        static::assertNotSame(FieldTypeRegistry::defaultTypes()[0], FieldTypeRegistry::defaultTypes()[0]);
        static::assertInstanceOf(TextField::class, FieldTypeRegistry::defaultTypes()[0]);
    }

    #[Test]
    public function getThrowsForAnUnknownKey(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('Unknown field type "non_existent"');

        (new FieldTypeRegistry())->get('non_existent');
    }

    #[Test]
    public function laterRegistrationReplacesATypeWithTheSameKey(): void
    {
        $replacement = self::customType('text', userSelectable: true);
        $registry    = new FieldTypeRegistry();
        $registry->register($replacement);

        static::assertSame($replacement, $registry->get('text'));
    }

    #[Test]
    public function registersExtraTypesPassedToTheConstructor(): void
    {
        $registry = new FieldTypeRegistry([self::customType('rating', userSelectable: true)]);

        static::assertTrue($registry->has('rating'));
    }

    #[Test]
    #[DataProvider('coreTypeProvider')]
    public function registersTheCoreType(string $key): void
    {
        $registry = new FieldTypeRegistry();

        static::assertSame($key, $registry->get($key)->key());
    }

    #[Test]
    public function userSelectableExcludesInternalTypes(): void
    {
        $registry = new FieldTypeRegistry();
        $registry->register(self::customType('honeypot', userSelectable: false));

        $keys = array_map(static fn(FieldTypeInterface $type): string => $type->key(), $registry->userSelectable());

        static::assertSame(
            ['honeypot'],
            array_values(array_diff(
                array_map(static fn(FieldTypeInterface $type): string => $type->key(), $registry->all()),
                $keys,
            )),
        );
    }
}
