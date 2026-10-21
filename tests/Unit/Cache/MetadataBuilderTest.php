<?php

declare(strict_types=1);

namespace Solo\Input\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\Input\Attributes\Cast;
use Solo\Input\Attributes\Caster;
use Solo\Input\Attributes\DateFormat;
use Solo\Input\Attributes\Generator;
use Solo\Input\Attributes\Items;
use Solo\Input\Attributes\PostProcess;
use Solo\Input\Attributes\Source\FromContext;
use Solo\Input\Attributes\Validate;
use Solo\Input\Cache\MetadataBuilder;
use Solo\Input\Contracts\CasterInterface;
use Solo\Input\Contracts\PostProcessorInterface;
use Solo\Input\Exceptions\ConfigurationException;
use Solo\Input\ProcessContext;
use Solo\Input\Attributes\Input;
use Solo\Input\Inputs;
use Solo\Input\Tests\ExpectsExceptionMessageParts;

final class MetadataBuilderTest extends TestCase
{
    use ExpectsExceptionMessageParts;

    private MetadataBuilder $builder;

    protected function setUp(): void
    {
        // Child traversal is covered through ReflectionCache in MetadataTraversalTest.
        $this->builder = new MetadataBuilder(static function (string $child): void {
        });
    }

    public function testChildFailurePrecedesRemainingParentChecks(): void
    {
        $failure = new \RuntimeException('Child loading failed');
        $builder = new MetadataBuilder(static function (string $child) use ($failure): void {
            throw $failure;
        });

        try {
            $builder->build(BuilderInvalidItemsInput::class);
            $this->fail('Expected the child loading exception');
        } catch (\RuntimeException $e) {
            $this->assertSame($failure, $e);
        }

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage(ConfigurationException::itemsRequiresArrayType(
            BuilderInvalidItemsInput::class,
            'items',
            'string',
        )->getMessage());
        $this->builder->build(BuilderInvalidItemsInput::class);
    }

    public function testUntypedPropertyIsNullable(): void
    {
        $property = $this->builder->build(UntypedPropertyInput::class)->properties['data'];

        $this->assertNull($property->type);
        $this->assertTrue($property->isNullable);
    }

    public function testAttributesOfOtherLibrariesAreLeftAlone(): void
    {
        // Never instantiated: the class does not even have to exist.
        $property = $this->builder->build(ForeignAttributeInput::class)->properties['name'];

        $this->assertSame('string', $property->type);
    }

    public function testOnlyValuesSomethingElseProducesGetAResultGuard(): void
    {
        $properties = $this->builder->build(ResultGuardInput::class)->properties;
        $guarded = array_keys(array_filter(
            $properties,
            static fn($property) => $property->resultGuard !== null,
        ));

        // Built-in decoders and child construction already yield the declared
        // type: a guard there only slows metadata building down.
        $this->assertSame(['money', 'code', 'userId', 'ref'], $guarded);
    }

    public function testDateFormatIsStoredWithItsFailureRule(): void
    {
        $properties = $this->builder->build(DateTypesInput::class)->properties;

        $this->assertSame('Y-m-d H:i:s', $properties['formatted']->dateFormat);
        $this->assertSame(
            ['rule' => 'date_format', 'params' => ['Y-m-d H:i:s']],
            $properties['formatted']->castFailure,
        );
        $this->assertSame(['rule' => 'date'], $properties['mutable']->castFailure);
    }

    /** @return iterable<string, non-empty-list<string>> */
    public static function misconfigurations(): iterable
    {
        yield 'class without #[Input]' => [
            NotMarkedInput::class,
            'Class ' . NotMarkedInput::class . ' is not an input class: mark it #[Input].',
        ];
        yield 'abstract input class' => [AbstractItemInput::class, 'cannot be instantiated'];
        yield 'parameter without a property' => [
            UnpromotedParameterInput::class,
            'Parameter $page of ' . UnpromotedParameterInput::class . '::__construct() has no public property',
        ];
        yield 'private field' => [PrivateFieldInput::class, 'Parameter $secret of', 'has no public property'];
        yield 'variadic parameter' => [VariadicParameterInput::class, 'Parameter $tags of', 'Variadic parameters'];
        yield 'field attribute outside the constructor' => [
            AttributeOutsideConstructorInput::class,
            'AttributeOutsideConstructorInput::$note has #[Validate] but is not a constructor parameter.',
        ];
        yield 'abstract #[Items] class' => [AbstractItemsInput::class, 'abstract'];
        yield 'missing #[Items] class' => [NonExistentItemsInput::class, 'class does not exist'];
        yield '#[Items] class without #[Input]' => [NotInputItemsInput::class, 'must be marked #[Input]'];
        yield 'cast type mismatch' => [
            InvalidCastInput::class,
            "cast type 'string' which is incompatible with property type 'int'",
        ];
        yield 'missing caster class' => [CasterWithNonExistentClassInput::class, 'invalid #[Caster('];
        yield 'not a generator' => [InvalidGeneratorInput::class, 'invalid #[Generator('];
        yield 'type no input can hold' => [
            IntersectionTypedInput::class,
            '::$items has type',
            'cannot be produced from request input',
        ];
        yield '#[DateFormat] on a string' => [DateFormatOnStringInput::class, "has #[DateFormat] but type 'string'"];
        yield '#[DateFormat] with a caster' => [
            DateFormatWithCasterInput::class,
            'has both #[DateFormat] and #[Caster]',
        ];
        yield 'misplaced library attribute' => [
            MisplacedAttributeInput::class,
            'MisplacedAttributeInput::$createdBy has',
            '#[Solo\Input\Attributes\FromContext], which is not a Solo Input property attribute.',
            'Did you mean #[Solo\Input\Attributes\Source\FromContext]?',
        ];
        yield 'misspelled class attribute' => [
            MisspelledClassAttributeInput::class,
            'Class ' . MisspelledClassAttributeInput::class . ' has #[Solo\Input\Attributes\NoTrimm], '
                . 'which is not a Solo Input class attribute.',
        ];
        yield '#[Input] on a field' => [
            InputOnFieldInput::class,
            '#[Solo\Input\Attributes\Input], which is not a Solo Input property attribute.',
        ];
    }

    /** @param class-string $class */
    #[DataProvider('misconfigurations')]
    public function testMisconfigurationFailsTheBuild(string $class, string ...$parts): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageParts(...$parts);
        $this->builder->build($class);
    }
}

#[Input]
final class UntypedPropertyInput
{
    public function __construct(
        /** @var mixed */
        public $data,
    ) {
    }
}

#[Input]
final class ForeignAttributeInput
{
    public function __construct(
        /** @phpstan-ignore attribute.notFound (another library's attribute, never loaded) */
        #[\Vendor\Orm\Column(length: 64)]
        public ?string $name = null,
    ) {
    }
}

#[Input]
final class ResultGuardInput
{
    public function __construct(
        public int $count = 0,
        /** @var list<string> */
        public array $tags = [],
        public ?\DateTimeImmutable $at = null,
        public ?BuilderItemInput $child = null,
        #[Caster(DummyCaster::class)]
        public string $money = '',
        #[PostProcess(GuardedPostProcessor::class)]
        public string $code = '',
        #[FromContext('userId')]
        public int $userId = 0,
        public int|string $ref = 0,
    ) {
    }
}

/** @implements PostProcessorInterface<string> */
final class GuardedPostProcessor implements PostProcessorInterface
{
    public function postProcess(mixed $value, ProcessContext $context): string
    {
        return (string) $value;
    }
}

#[Input]
final class InvalidCastInput
{
    public function __construct(
        #[Cast('string')]
        public int $id,
    ) {
    }
}

#[Input]
final class CasterWithNonExistentClassInput
{
    public function __construct(
        /** @phpstan-ignore-next-line — intentional: class does not exist */
        #[Caster('NonExistentCasterClass')]
        public string $value,
    ) {
    }
}

/** @implements CasterInterface<mixed> */
final class DummyCaster implements CasterInterface
{
    public function cast(mixed $value, ProcessContext $context): mixed
    {
        return $value;
    }
}

#[Input]
final class InvalidGeneratorInput
{
    public function __construct(
        /** @phpstan-ignore-next-line — intentional: class does not implement GeneratorInterface */
        #[Generator(NotAGenerator::class)]
        public string $id,
    ) {
    }
}

final class NotAGenerator
{
    public function generate(): string
    {
        return 'not_a_generator';
    }
}

#[Input]
final class NonExistentItemsInput
{
    public function __construct(
        /**
         * @var array<mixed>|null
         * @phpstan-ignore-next-line — intentional: class does not exist
         */
        #[Items('NonExistentItemClass')]
        public ?array $items = null,
    ) {
    }
}

#[Input]
final class NotInputItemsInput
{
    public function __construct(
        /** @var array<mixed>|null */
        #[Items(NotAGenerator::class)]
        public ?array $items = null,
    ) {
    }
}

final class NotMarkedInput
{
    public function __construct(
        public string $name = '',
    ) {
    }
}

#[Input]
final class UnpromotedParameterInput
{
    public int $count = 0;

    public function __construct(int $page = 1)
    {
        $this->count = $page;
    }
}

#[Input]
final class PrivateFieldInput
{
    public function __construct(
        private string $secret = '',
    ) {
    }

    public function secret(): string
    {
        return $this->secret;
    }
}

#[Input]
final class VariadicParameterInput
{
    /** @var list<string> */
    public array $tags;

    public function __construct(string ...$tags)
    {
        $this->tags = array_values($tags);
    }
}

#[Input]
final class AttributeOutsideConstructorInput
{
    #[Validate('string')]
    public string $note = '';

    public function __construct(
        public string $name = '',
    ) {
    }
}

#[Input]
final class InputOnFieldInput
{
    public function __construct(
        /** @phpstan-ignore attribute.target (deliberately misplaced) */
        #[Input]
        public string $name = '',
    ) {
    }
}

#[Input]
abstract class AbstractItemInput
{
    public function __construct(
        public string $product,
    ) {
    }
}

#[Input]
final class AbstractItemsInput
{
    public function __construct(
        /** @var array<int, array<string, mixed>>|null */
        #[Items(AbstractItemInput::class)]
        public ?array $items = null,
    ) {
    }
}

#[Input]
final class MisplacedAttributeInput
{
    public function __construct(
        /** @phpstan-ignore attribute.notFound (source attributes live in Attributes\Source) */
        #[\Solo\Input\Attributes\FromContext('authUserId')]
        public int $createdBy = 0,
    ) {
    }
}

/** @phpstan-ignore attribute.notFound (intentionally misspelled) */
#[\Solo\Input\Attributes\NoTrimm]
#[Input]
final class MisspelledClassAttributeInput
{
    public function __construct(
        public ?string $name = null,
    ) {
    }
}

#[Input]
final class DateTypesInput
{
    public function __construct(
        public \DateTime $mutable,
        #[DateFormat('Y-m-d H:i:s')]
        public \DateTime $formatted,
        #[DateFormat('Y-m-d')]
        public mixed $anything = null,
    ) {
    }
}

#[Input]
final class DateFormatOnStringInput
{
    public function __construct(
        #[DateFormat('Y-m-d')]
        public string $day,
    ) {
    }
}

#[Input]
final class DateFormatWithCasterInput
{
    public function __construct(
        #[DateFormat('Y-m-d')]
        #[Caster(DummyCaster::class)]
        public \DateTimeImmutable $day,
    ) {
    }
}

#[Input]
final class IntersectionTypedInput
{
    public function __construct(
        /** @var \Countable&\ArrayAccess<int, mixed> */
        public \Countable&\ArrayAccess $items,
    ) {
    }
}

#[Input]
final class BuilderItemInput
{
    public function __construct(
        public int $quantity = 0,
    ) {
    }
}

#[Input]
final class BuilderInvalidItemsInput
{
    public function __construct(
        #[Items(BuilderItemInput::class)]
        public string $items,
    ) {
    }
}
