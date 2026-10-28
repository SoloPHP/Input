<?php

declare(strict_types=1);

namespace Solo\Input\Tests\Integration\Stages;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solo\Contracts\Validator\ValidatorInterface;
use Solo\Input\Attributes\{Caster, Generator, Input, Items, Normalize, PostProcess, Validate};
use Solo\Input\Attributes\Source\{FromBody, FromContext};
use Solo\Input\Contracts\{CasterInterface, GeneratorInterface, NormalizerInterface, PostProcessorInterface};
use Solo\Input\Exceptions\{FieldValidationException, ValidationException};
use Solo\Input\{InputFactory, ProcessContext};

final class ProcessingStagesTest extends TestCase
{
    private InputFactory $factory;

    protected function setUp(): void
    {
        Trace::$stages = [];
        $this->factory = new InputFactory(new RawValidator());
    }

    public function testACasterGetsABlankOnlyWhereItsTypeHoldsAString(): void
    {
        $dto = $this->factory->fromArray(BlankCustomInput::class, [
            'text' => '', 'anything' => '', 'list' => [], 'iterable' => [],
        ]);
        self::assertSame('', $dto->text);
        self::assertSame('', $dto->anything);
        self::assertSame([], $dto->list);
        self::assertSame(['cast', 'cast', 'cast', 'cast', 'post', 'post', 'post', 'post'], Trace::$stages);

        // A list holds no "": it is no value, and the caster never sees it.
        Trace::$stages = [];
        try {
            $this->factory->fromArray(BlankCustomInput::class, [
                'text' => 'a', 'anything' => 'b', 'list' => '', 'iterable' => '',
            ]);
            self::fail('Expected validation failure');
        } catch (ValidationException $e) {
            self::assertSame(
                ['list' => [['rule' => 'required']], 'iterable' => [['rule' => 'required']]],
                $e->getErrors(),
            );
        }
        self::assertSame(['cast', 'cast'], Trace::$stages);
    }

    public function testSparseItemErrorPathsKeepOriginalKeysAndPreventFinalization(): void
    {
        try {
            $this->factory->fromArray(TreeInput::class, [
                'title' => 'raw-invalid',
                'children' => [7 => ['amount' => 'bad'], 'last' => ['amount' => 'bad'], 12 => 'invalid'],
            ]);
            self::fail('Expected validation failure');
        } catch (ValidationException $e) {
            self::assertSame([
                'title' => [['rule' => 'raw']],
                'children.7.amount' => [['rule' => 'integer', 'params' => ['decimal']]],
                'children.last.amount' => [['rule' => 'integer', 'params' => ['decimal']]],
                'children.12' => [['rule' => 'array']],
            ], $e->getErrors());
            self::assertNotContains('post', Trace::$stages);
            self::assertNotContains('generate', Trace::$stages);
            self::assertNotContains('children', Trace::$stages);
        }
    }

    public function testNormalizerFieldErrorAlsoUsesItsInputPath(): void
    {
        try {
            $this->factory->fromArray(RejectedNormalizationInput::class, ['payload' => ['count' => 'bad']]);
            self::fail('Expected validation failure');
        } catch (ValidationException $e) {
            self::assertSame(['payload.count' => [['rule' => 'normalization']]], $e->getErrors());
            self::assertSame(['normalize'], Trace::$stages);
        }
    }

    public function testContextCasterFailureIsAnApplicationError(): void
    {
        try {
            $this->factory->fromArray(ContextInput::class, [], context: ['amount' => 'bad']);
            self::fail('Expected application error');
        } catch (\UnexpectedValueException $e) {
            self::assertInstanceOf(FieldValidationException::class, $e->getPrevious());
            self::assertStringContainsString("Context value 'amount'", $e->getMessage());
            self::assertNotContains('post', Trace::$stages);
        }
    }

    public function testParentTypeRejectsAnUnrelatedCasterResult(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('returned stdClass');
        $this->factory->fromArray(ParentRelativeInput::class, ['value' => new \stdClass()]);
    }

    /** @return iterable<string, array{class-string}> */
    public static function invalidRejections(): iterable
    {
        yield 'normalizer aggregate exception' => [AggregateNormalizerInput::class];
        yield 'caster aggregate exception' => [AggregateCasterInput::class];
        yield 'post aggregate exception' => [AggregatePostInput::class];
        yield 'generator aggregate exception' => [AggregateGeneratorInput::class];
    }

    /** @param class-string $class */
    #[DataProvider('invalidRejections')]
    public function testInvalidRejectionIsAContractError(string $class): void
    {
        try {
            $this->factory->fromArray($class, ['amount' => '2']);
            self::fail('Expected contract error');
        } catch (\LogicException $e) {
            self::assertInstanceOf(ValidationException::class, $e->getPrevious());
        }
    }
}

final class Trace
{
    /** @var list<string> */
    public static array $stages = [];

    public static function record(string $stage): void
    {
        self::$stages[] = $stage;
    }
}

/** @implements CasterInterface<mixed> */
final class IdentityCaster implements CasterInterface
{
    public function cast(mixed $value, ProcessContext $context): mixed
    {
        Trace::record('cast');
        return $value;
    }
}

/** @implements PostProcessorInterface<mixed> */
final class IdentityProcessor implements PostProcessorInterface
{
    public function postProcess(mixed $value, ProcessContext $context): mixed
    {
        Trace::record('post');
        return $value;
    }
}

#[Input]
class RelativeInput
{
}

// An input subclass: #[Input] on the parent applies.
class ParentRelativeInput extends RelativeInput
{
    public function __construct(
        #[Caster(IdentityCaster::class)]
        public parent $value,
    ) {
    }
}

#[Input]
class BlankCustomInput
{
    public function __construct(
        #[Caster(IdentityCaster::class)]
        #[PostProcess(IdentityProcessor::class)]
        public string $text,
        #[Caster(IdentityCaster::class)]
        #[PostProcess(IdentityProcessor::class)]
        public mixed $anything,
        /** @var list<mixed> */
        #[Caster(IdentityCaster::class)]
        #[PostProcess(IdentityProcessor::class)]
        public array $list,
        /** @var iterable<mixed> */
        #[Caster(IdentityCaster::class)]
        #[PostProcess(IdentityProcessor::class)]
        public iterable $iterable,
    ) {
    }
}

final class RawValidator implements ValidatorInterface
{
    public function validate(array $data, array $rules): array
    {
        Trace::record('validate');
        $errors = [];
        foreach ($rules as $name => $rule) {
            if (($data[$name] ?? null) === 'raw-invalid') {
                $errors[$name] = [['rule' => 'raw']];
            }
        }
        return $errors;
    }
}

final class InputNormalizer implements NormalizerInterface
{
    public function normalize(mixed $value, ProcessContext $context): mixed
    {
        Trace::record('normalize');
        return match ($context->config['mode'] ?? 'keep') {
            'reject' => throw new FieldValidationException('normalization'),
            'aggregate' => throw new ValidationException(['unrelated' => [['rule' => 'bad']]]),
            default => $value,
        };
    }
}

/** @implements CasterInterface<mixed> */
final class IntegerCaster implements CasterInterface
{
    public function cast(mixed $value, ProcessContext $context): mixed
    {
        Trace::record('cast');
        if (($context->config['mode'] ?? '') === 'aggregate') {
            throw new ValidationException(['unrelated' => [['rule' => 'bad']]]);
        }
        if (!is_int($value) && (!is_string($value) || preg_match('/^-?[0-9]+$/D', $value) !== 1)) {
            throw new FieldValidationException('integer', ['decimal']);
        }
        return (int) $value;
    }
}

/** @implements PostProcessorInterface<mixed> */
final class ValueProcessor implements PostProcessorInterface
{
    public function postProcess(mixed $value, ProcessContext $context): mixed
    {
        Trace::record('post');
        return match ($context->config['mode'] ?? '') {
            'aggregate' => throw new ValidationException(['amount' => [['rule' => 'late']]]),
            default => $value,
        };
    }
}

final class IdGenerator implements GeneratorInterface
{
    public function generate(array $options = []): int
    {
        Trace::record('generate');
        return match ($options['mode'] ?? '') {
            'aggregate' => throw new ValidationException(['amount' => [['rule' => 'late']]]),
            default => 99,
        };
    }
}

#[Input]
class ChildInput
{
    public function __construct(
        #[Normalize(InputNormalizer::class)]
        #[Validate('integer')]
        #[Caster(IntegerCaster::class)]
        #[PostProcess(ValueProcessor::class)]
        public int $amount,
        #[Generator(IdGenerator::class)]
        public int $id,
    ) {
    }
}

/** @implements PostProcessorInterface<list<ChildInput>> */
final class ChildrenProcessor implements PostProcessorInterface
{
    public function postProcess(mixed $value, ProcessContext $context): array
    {
        Trace::record('children');
        return $value;
    }
}

#[Input]
class TreeInput
{
    public function __construct(
        #[Validate('string')]
        public string $title,
        /** @var list<ChildInput> */
        #[Items(ChildInput::class)]
        #[PostProcess(ChildrenProcessor::class)]
        public array $children,
        #[Generator(IdGenerator::class)]
        public int $id,
    ) {
    }
}

#[Input]
class RejectedNormalizationInput
{
    public function __construct(
        #[FromBody('payload.count')]
        #[Normalize(InputNormalizer::class, config: ['mode' => 'reject'])]
        public int $amount,
    ) {
    }
}

#[Input]
class ContextInput
{
    public function __construct(
        #[FromContext('amount')]
        #[Caster(IntegerCaster::class)]
        #[PostProcess(ValueProcessor::class)]
        public int $amount,
    ) {
    }
}

#[Input]
class AggregateNormalizerInput
{
    public function __construct(
        #[Normalize(InputNormalizer::class, config: ['mode' => 'aggregate'])]
        public int $amount,
    ) {
    }
}

#[Input]
class AggregateCasterInput
{
    public function __construct(
        #[Caster(IntegerCaster::class, config: ['mode' => 'aggregate'])]
        public int $amount,
    ) {
    }
}

#[Input]
class AggregatePostInput
{
    public function __construct(
        #[PostProcess(ValueProcessor::class, config: ['mode' => 'aggregate'])]
        public int $amount,
    ) {
    }
}

#[Input]
class AggregateGeneratorInput
{
    public function __construct(
        #[Generator(IdGenerator::class, options: ['mode' => 'aggregate'])]
        public int $id,
    ) {
    }
}
