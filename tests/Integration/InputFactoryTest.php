<?php

declare(strict_types=1);

namespace Solo\Input\Tests\Integration;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Solo\Input\Attributes\Cast;
use Solo\Input\Attributes\Caster;
use Solo\Input\Attributes\EmptyToNull;
use Solo\Input\Attributes\Generator;
use Solo\Input\Attributes\Group;
use Solo\Input\Attributes\Items;
use Solo\Input\Attributes\NoTrim;
use Solo\Input\Attributes\PostProcess;
use Solo\Input\Attributes\Normalize;
use Solo\Input\Attributes\Source\FromBody;
use Solo\Input\Attributes\Source\FromContext;
use Solo\Input\Attributes\Source\FromRoute;
use Solo\Input\Attributes\Validate;
use Solo\Input\Contracts\CasterInterface;
use Solo\Input\Contracts\GeneratorInterface;
use Solo\Input\Contracts\NormalizerInterface;
use Solo\Input\Contracts\PostProcessorInterface;
use Solo\Input\Exceptions\ConfigurationException;
use Solo\Input\Exceptions\ValidationException;
use Solo\Input\ProcessContext;
use Solo\Input\Attributes\Input;
use Solo\Input\Inputs;
use Solo\Input\InputFactory;
use Solo\Contracts\Validator\ValidatorInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ServerRequestInterface;
use Solo\Input\Tests\ExpectsExceptionMessageParts;

#[AllowMockObjectsWithoutExpectations]
final class InputFactoryTest extends TestCase
{
    use ExpectsExceptionMessageParts;

    private InputFactory $factory;
    private ValidatorInterface&MockObject $validator;

    protected function setUp(): void
    {
        $this->validator = $this->createMock(ValidatorInterface::class);
        $this->factory = new InputFactory($this->validator);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, mixed>      $query
     */
    private function makeRequest(
        ?array $body = null,
        array $query = [],
    ): ServerRequestInterface {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($body);
        $request->method('getQueryParams')->willReturn($query);
        return $request;
    }

    // ===== auto-trim =====

    public function testNoTrimWithEmptyToNullStillReadsBlankAsNull(): void
    {
        $dto = $this->factory->fromArray(NoTrimClassInput::class, ['a' => '   ', 'c' => '   ']);
        $this->assertSame('   ', $dto->a, 'no #[EmptyToNull]: kept as sent');
        $this->assertNull($dto->c, 'blank is still no value');

        $dto = $this->factory->fromArray(NoTrimClassInput::class, ['c' => ' z ']);
        $this->assertSame(' z ', $dto->c, 'a filled value is not trimmed');
    }

    // ===== union types =====

    public function testUnionConvertsAnotherValueInPhpsOrderWithoutLoss(): void
    {
        $dto = $this->factory->fromArray(UnionTypesInput::class, [
            'amount' => '5',
            'ratio'  => '2.5',
            'code'   => 2.0,
            'flag'   => 'yes',
            'tags'   => 7,
        ]);

        $this->assertSame(5, $dto->amount, 'int before float');
        $this->assertSame(2.5, $dto->ratio);
        $this->assertSame(2, $dto->code, 'a whole float becomes the int member');
        $this->assertTrue($dto->flag);
        $this->assertSame('7', $dto->tags);
    }

    public function testFalseMemberKeepsItsLiteral(): void
    {
        $terms = fn(mixed $nickname) => $this->factory->fromArray(TermsInput::class, [
            'accepted' => true,
            'nickname' => $nickname,
        ]);
        $this->assertFalse($terms(false)->nickname);
        $this->assertSame('x', $terms('x')->nickname);

        try {
            $terms(true);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['nickname' => [['rule' => 'string']]], $e->getErrors());
        }
    }

    public function testDecimalCastWritesAPlainDecimalString(): void
    {
        $rate = fn(mixed $input) => $this->factory->fromArray(DecimalInput::class, ['rate' => $input])->rate;
        $this->assertSame('0.00001', $rate(0.00001));
        $this->assertSame('0.00001', $rate('1e-5'));
        $this->assertSame('41.5', $rate('41.50'));
        $this->assertNull($rate(''));

        try {
            $rate('abc');
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['rate' => [['rule' => 'numeric']]], $e->getErrors());
        }
    }

    public function testIterableIsCastLikeAnArray(): void
    {
        $cases = [
            'a,b'       => ['a', 'b'],
            '["x","y"]' => ['x', 'y'],
        ];
        foreach ($cases as $input => $expected) {
            $this->assertSame($expected, $this->factory->fromArray(IterableInput::class, ['tags' => $input])->tags);
        }

        $dto = $this->factory->fromArray(IterableInput::class, ['tags' => ['k' => 'v']]);
        $this->assertSame(['k' => 'v'], $dto->tags);
    }

    public function testEmptyStringIsReadByTheTypesOwnReader(): void
    {
        // An enum with a "" case reads "" as that case, not as no value.
        $filter = fn(string $input) => $this->factory->fromArray(BlankFilterInput::class, ['filter' => $input]);
        $this->assertSame(BlankFilter::All, $filter('')->filter);
        $this->assertSame(BlankFilter::Active, $filter('active')->filter);
    }

    // ===== application-supplied values =====

    public function testGeneratorReturningNullForARequiredPropertyThrows(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Value generated by');
        $this->factory->fromArray(NullGeneratedInput::class, []);
    }

    public function testPostProcessedContextListMembersAreChecked(): void
    {
        // The context list is checked as it is read, before its
        // post-processor runs.
        $line = new LineItemInput(sku: 'A-1');
        $dto = $this->factory->fromArray(PostProcessedContextLinesInput::class, [], context: ['lines' => [$line]]);
        $this->assertSame([$line], $dto->lines);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage("Context value 'lines' holds array at key 0, but");
        $this->factory->fromArray(PostProcessedContextLinesInput::class, [], context: [
            'lines' => [['sku' => 'A-1']],
        ]);
    }

    public function testInvalidContextBlocksFinalizationButNotPureNormalization(): void
    {
        // Pure normalizers run in resolve; generators wait for valid context.
        CountingGenerator::$calls = 0;
        CountingJsonNormalizer::$calls = 0;
        $body = ['tags' => '["a"]'];

        foreach ([['tenant' => null], ['tenant' => 'acme', 'address' => ['city' => 'Kyiv']]] as $index => $context) {
            try {
                $this->factory->fromArray(ContextBeforeHandlersInput::class, $body, context: $context);
                $this->fail('Expected UnexpectedValueException');
            } catch (\UnexpectedValueException) {
                $this->assertSame($index + 1, CountingJsonNormalizer::$calls, (string) json_encode($context));
                $this->assertSame(0, CountingGenerator::$calls, (string) json_encode($context));
            }
        }

        $dto = $this->factory->fromArray(ContextBeforeHandlersInput::class, $body, context: ['tenant' => 'acme']);
        $this->assertSame(['a'], $dto->tags);
        $this->assertSame(1, $dto->sequence);
    }

    public function testContextValueThatFitsNoUnionMemberThrows(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage("Context value 'limit' does not fit");
        $this->factory->fromArray(ContextUnionInput::class, [], context: ['limit' => 'many']);
    }

    // ===== #[EmptyToNull] =====

    public function testBlankStringFallsBackToTheDefaultOrIsRequired(): void
    {
        $dto = $this->factory->fromArray(BlankFieldsInput::class, ['code' => 'A', 'sort' => '']);
        $this->assertSame('id', $dto->sort);

        try {
            $this->factory->fromArray(BlankFieldsInput::class, ['code' => '']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['code' => [['rule' => 'required']]], $e->getErrors());
        }
    }

    // ===== sources =====

    public function testRouteAndContextPropertiesNeverReadTheBody(): void
    {
        $body = ['id' => '999', 'authUserId' => 999, 'createdBy' => 999];

        $dto = $this->factory->fromArray(SourcedInput::class, $body);
        $this->assertNull($dto->id);
        $this->assertNull($dto->createdBy);
        $this->assertSame([], Inputs::provided($dto));

        $dto = $this->factory->fromArray(
            SourcedInput::class,
            $body,
            route: ['id' => '5'],
            context: ['authUserId' => 7],
        );
        $this->assertSame(5, $dto->id);
        $this->assertSame(7, $dto->createdBy);
    }

    // ===== constructor =====

    public function testAbsentFieldWithoutADefaultFailsRequired(): void
    {
        try {
            $this->factory->fromArray(ProductInput::class, ['note' => 'x']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            // Declaration order; `stock` has a default and may be absent.
            $this->assertSame([
                'name' => [['rule' => 'required']],
                'price' => [['rule' => 'required']],
            ], $e->getErrors());
        }
    }

    public function testTheValidatorsVerdictOnAnAbsentFieldIsKept(): void
    {
        $this->validator->method('validate')->willReturn(['name' => [['rule' => 'required_with', 'params' => ['x']]]]);

        try {
            $this->factory->fromArray(ProductInput::class, ['price' => '9.50']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['name' => [['rule' => 'required_with', 'params' => ['x']]]], $e->getErrors());
        }
    }

    public function testMissingContextValueWithoutADefaultIsAnApplicationError(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage("Context value 'limit' is missing, but " . ContextUnionInput::class
            . '::$limit has no default.');
        $this->factory->fromArray(ContextUnionInput::class, []);
    }

    public function testReadonlyDtoIsBuiltThroughItsConstructor(): void
    {
        $dto = $this->factory->fromArray(ProductInput::class, ['name' => 'Chair', 'price' => '9.50']);

        $this->assertSame('Chair', $dto->name);
        $this->assertSame(9.5, $dto->price);
        $this->assertSame(0, $dto->stock);
        $this->assertSame(['name', 'price'], Inputs::provided($dto));

        // Through reflection: static analysis reports a direct assignment
        // differently on PHP 8.3 and 8.4 (readonly implies protected(set)).
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Cannot modify readonly property');
        (new \ReflectionProperty($dto, 'name'))->setValue($dto, 'Table');
    }

    public function testTheConstructorRunsAfterTheWholeInputIsValid(): void
    {
        // The constructor's own invariant sees decoded values only.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('stock exceeds 1000');
        $this->factory->fromArray(ProductInput::class, ['name' => 'Chair', 'price' => '1', 'stock' => '5000']);
    }

    // ===== validation payload =====

    public function testAbsentFieldReachesTheValidatorAsRulesWithoutAValue(): void
    {
        // Its default in the payload would make the field look sent as null,
        // so `email` would fail for a field nobody sent and nothing requires.
        // `note` has no presence rule and goes all the same: whether a field
        // may be missing is the validator's to decide.
        $this->validator->expects($this->once())->method('validate')
            ->with(['type' => 'person'], ['email' => 'required_if:type,company|email', 'note' => 'string|max:10'])
            ->willReturn([]);

        $dto = $this->factory->fromArray(ConditionalEmailInput::class, ['type' => 'person']);

        $this->assertNull($dto->email);
        $this->assertSame(['type'], Inputs::provided($dto));
    }

    // ===== provided() =====

    public function testEachListItemReportsWhatItsOwnInputSupplied(): void
    {
        $dto = $this->factory->fromArray(PatchLinesInput::class, [
            'lines' => [['sku' => 'A'], ['qty' => '2'], ['sku' => 'C', 'qty' => '3'], ['sku' => null]],
        ]);

        // Items with the same fields share one set of names; an explicit null
        // is supplied, an absent key is not.
        $this->assertSame(
            [['sku'], ['qty'], ['sku', 'qty'], ['sku']],
            array_map(static fn(LineItemInput $line) => Inputs::provided($line), $dto->lines),
        );
        $this->assertSame(['lines' => [
            ['sku' => 'A'],
            ['qty' => 2],
            ['sku' => 'C', 'qty' => 3],
            ['sku' => null],
        ]], Inputs::toArray($dto, onlyProvided: true));
    }

    public function testProvidedTracksInputRatherThanLaterDtoChanges(): void
    {
        $dto = $this->factory->fromArray(PatchAddressInput::class, ['city' => 'Kyiv']);
        $names = Inputs::provided($dto);
        $names[] = 'zip';
        $dto->zip = '01001';
        unset($dto->city);

        $this->assertSame(['city'], Inputs::provided($dto));
        $this->assertTrue(Inputs::isProvided($dto, 'city'));
        $this->assertFalse(Inputs::isProvided($dto, 'zip'));
        $this->assertSame([], Inputs::toArray($dto, onlyProvided: true));
    }

    // ===== generator =====

    public function testContainerProvidesHandlersWithDependencies(): void
    {
        $container = new ArrayContainer([
            TestGeneratorWithDependency::class => new TestGeneratorWithDependency('from_container'),
        ]);
        $handler = new InputFactory($this->validator, container: $container);

        $dto = $handler->fromArray(RegisteredGeneratorInput::class, ['name' => 'Test']);
        $this->assertSame('from_container_generated', $dto->id);
        $this->assertSame(1, $container->gets);

        // A registered instance takes precedence over the container.
        $other = new InputFactory($this->validator, container: $container);
        $other->register(TestGeneratorWithDependency::class, new TestGeneratorWithDependency('registered'));
        $dto = $other->fromArray(RegisteredGeneratorInput::class, ['name' => 'Test']);
        $this->assertSame('registered_generated', $dto->id);
        $this->assertSame(1, $container->gets, 'the container was not asked');
    }

    public function testContainerEntryOfTheWrongTypeIsAConfigurationError(): void
    {
        $handler = new InputFactory(
            $this->validator,
            container: new ArrayContainer([TestGeneratorWithDependency::class => new \stdClass()]),
        );

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('is a stdClass, not an instance of');
        $handler->fromArray(RegisteredGeneratorInput::class, ['name' => 'Test']);
    }

    // ===== items =====

    public function testItemsExplicitNullDoesNotThrow(): void
    {
        // Explicit `{"items": null}` on a nullable items property must pass
        // through, not raise spurious ValidationException('array').
        $request = $this->makeRequest(body: ['name' => 'Order #1', 'items' => null]);

        $dto = $this->factory->fromBody(OrderInput::class, $request);

        $this->assertNull($dto->items);
    }

    // ===== groups =====

    public function testGroupSkipsNullScalarMembers(): void
    {
        // Pre-2.0 group() emitted ['search' => null, 'deleted' => null]; now nulls are skipped.
        $request = $this->makeRequest(query: ['page' => '1']);

        $dto = $this->factory->fromQuery(GroupedInput::class, $request);

        $this->assertSame([], Inputs::group($dto, 'criteria'));
    }

    public function testCasterBuiltFilterJoinsTheGroupUnderItsPropertyName(): void
    {
        // The shape solophp/repository criteria take: conditions under fixed
        // keys, a filter object for what one key cannot say.
        $request = $this->makeRequest(query: ['supplier_id' => '7', 'status' => ['paid', 'pending'], 'q' => 'chair']);

        $criteria = Inputs::group($this->factory->fromQuery(FilterGroupInput::class, $request), 'criteria');

        $this->assertSame(['supplier_id', 'status IN', 'q'], array_keys($criteria));
        $this->assertSame(7, $criteria['supplier_id']);
        $this->assertSame(['paid', 'pending'], $criteria['status IN']);
        $filter = $criteria['q'];
        $this->assertInstanceOf(KeywordFilter::class, $filter);
        $this->assertSame('chair', $filter->keyword);
    }

    // ===== PSR-7 object body =====

    public function testObjectBodyIsNormalisedToArray(): void
    {
        // Middleware-decoded JSON (`json_decode($json)` without assoc=true)
        // returns stdClass — body normalisation must convert it to array.
        $body = (object)['name' => 'Alice', 'nested' => (object)['key' => 'value']];
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($body);
        $request->method('getQueryParams')->willReturn([]);

        $dto = $this->factory->fromBody(NoAttributeInput::class, $request);

        $this->assertSame('Alice', $dto->name);
    }

    // ===== register() type guard =====

    public function testRegisterWithMismatchedInstanceThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a');
        $this->factory->register(\DateTimeImmutable::class, new \stdClass());
    }

    // ===== configuration exceptions =====

    /** @return iterable<string, non-empty-list<string>> */
    public static function misconfigurations(): iterable
    {
        yield 'two sources' => [FromBodyAndRouteConflictInput::class, 'multiple source attributes'];
        yield 'class name in #[Cast]' => [CastWithClassInput::class, "#[Cast('Solo"];
        yield 'unknown cast type' => [UnknownCastInput::class, 'which is neither a built-in type'];
        yield 'decimal cast on an int' => [DecimalCastOnIntInput::class, "cast type 'decimal'", "type 'int'"];
        yield '#[Cast] with #[Caster]' => [CastAndCasterConflictInput::class, 'has both #[Cast] and #[Caster]'];
        yield '#[Generator] with a source' => [GeneratorWithFromRouteInput::class, 'FromRoute'];
        yield '#[Items] with #[Cast]' => [ItemsWithCastInput::class, 'Items', 'Cast'];
    }

    /** @param class-string $class */
    #[DataProvider('misconfigurations')]
    public function testMisconfigurationFailsTheRequest(string $class, string ...$parts): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageParts(...$parts);
        $this->factory->fromArray($class, []);
    }

    // ===== processors =====

    public function testNestedPostProcessorsReceiveDecodedChildren(): void
    {
        $handle = fn(array $input) => $this->factory->fromArray(PostProcessedChildrenInput::class, $input);

        $dto = $handle(['lines' => [['sku' => 'A', 'qty' => '2']], 'address' => ['city' => 'Kyiv']]);
        $this->assertSame([['sku' => 'A', 'qty' => 2]], array_map(Inputs::toArray(...), $dto->lines));
        $this->assertSame('Kyiv', $dto->address?->city);

        // "" is an empty list and no object.
        $dto = $handle(['lines' => '', 'address' => '']);
        $this->assertSame([], $dto->lines);
        $this->assertNull($dto->address);

        // Any other string is left for the children to reject.
        try {
            $handle(['lines' => 'abc']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['lines' => [['rule' => 'array']]], $e->getErrors());
        }
    }

    public function testUnregisteredHandlerWithDependenciesFailsClearly(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Provide it through the container passed to InputFactory, or register');
        $this->factory->fromArray(RegisteredGeneratorInput::class, ['name' => 'x']);
    }

    // ===== error keys follow the input =====

    public function testCollidingItemPathsKeepEveryError(): void
    {
        try {
            $this->factory->fromArray(CollidingItemsInput::class, [
                'items' => ['a' => ['code' => 'bad'], 'a.code' => 'bad'],
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['items.a.code' => [['rule' => 'integer'], ['rule' => 'array']]], $e->getErrors());
        }
    }

    public function testThrownValidatorErrorsKeepPathsAndOrder(): void
    {
        $this->validator->expects($this->exactly(3))->method('validate')->willReturnCallback(
            static function (array $data, array $rules): never {
                // Deliberately report fields in a different order from the DTO.
                throw new ValidationException(isset($rules['name'])
                    ? [
                        'global.z' => [['rule' => 'request']],
                        'productId' => [['rule' => 'exists', 'params' => ['products', 'id']]],
                        'name' => [['rule' => 'required']],
                        'global.a' => [['rule' => 'request']],
                    ]
                    : [
                        'quantity' => [['rule' => 'min', 'params' => ['1']]],
                        'product' => [['rule' => 'required']],
                        'global' => [['rule' => 'item']],
                    ]);
            }
        );

        try {
            $this->factory->fromArray(InputKeyedInput::class, [
                'display_name' => 'x',
                'order' => ['lines' => [3 => ['quantity' => 0], 1 => ['quantity' => 0]]],
            ], route: ['product_id' => '1'], context: ['authUserId' => 7]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $expected = [
                'display_name' => [['rule' => 'required']],
                'product_id' => [['rule' => 'exists', 'params' => ['products', 'id']]],
                'order.lines.3.product' => [['rule' => 'required']],
                'order.lines.3.quantity' => [['rule' => 'min', 'params' => ['1']]],
                'order.lines.3.global' => [['rule' => 'item']],
                'order.lines.1.product' => [['rule' => 'required']],
                'order.lines.1.quantity' => [['rule' => 'min', 'params' => ['1']]],
                'order.lines.1.global' => [['rule' => 'item']],
                'global.z' => [['rule' => 'request']],
                'global.a' => [['rule' => 'request']],
            ];
            $this->assertSame($expected, $e->getErrors());
            $this->assertSame('Validation failed: ' . implode(', ', array_keys($expected)), $e->getMessage());
            $this->assertSame(422, $e->getCode());
        }
    }

    // ===== nested DTOs =====

    public function testSelfAndParentTypesNameTheirDeclaringClasses(): void
    {
        $origin = $this->factory->fromArray(NodeInput::class, ['name' => 'origin']);
        $root = $this->factory->fromArray(NodeInput::class, ['name' => 'root']);

        $dto = $this->factory->fromArray(LeafNodeInput::class, [
            'name' => 'leaf',
            'next' => ['name' => 'next'],
            'base' => ['name' => 'base'],
        ], context: ['origin' => $origin, 'root' => $root]);

        // `self` is the declaring class, not the class being handled.
        $this->assertNotNull($dto->next);
        $this->assertNotNull($dto->base);
        $this->assertSame(NodeInput::class, $dto->next::class);
        $this->assertSame('next', $dto->next->name);
        $this->assertSame(NodeInput::class, $dto->base::class);
        $this->assertSame('base', $dto->base->name);
        $this->assertSame($origin, $dto->origin);
        $this->assertSame($root, $dto->root);
    }
}

// ============================================================================
// Fixtures
// ============================================================================

/** @implements CasterInterface<mixed> */
final class CustomCaster implements CasterInterface
{
    public function cast(mixed $value, ProcessContext $context): int
    {
        return (int) $value * 2;
    }
}

#[Input]
final class GroupedInput
{
    public function __construct(
        #[Group('criteria')]
        public ?string $search = null,
        #[Validate('in:only,with')]
        #[Group('criteria')]
        public ?string $deleted = null,
        #[Validate('min:1')]
        public int $page = 1,
    ) {
    }
}

/** Stands in for a repository filter object such as `Solo\Repository\Filter`. */
final readonly class KeywordFilter
{
    public function __construct(public string $keyword)
    {
    }
}

/** @implements CasterInterface<KeywordFilter> */
final class KeywordFilterCaster implements CasterInterface
{
    public function cast(mixed $value, ProcessContext $context): KeywordFilter
    {
        return new KeywordFilter(is_string($value) ? $value : '');
    }
}

#[Input]
final class FilterGroupInput
{
    public function __construct(
        #[Group('criteria')]
        public int $supplier_id,
        /** @var list<string> */
        #[Group('criteria', mapTo: 'status IN')]
        public array $status = [],
        #[Caster(KeywordFilterCaster::class)]
        #[Group('criteria')]
        public ?KeywordFilter $q = null,
    ) {
    }
}

#[Input]
final class NoAttributeInput
{
    public function __construct(
        #[Validate('required|string')]
        public string $name,
    ) {
    }
}

#[Input]
final readonly class ProductInput
{
    public function __construct(
        #[Validate('string')]
        public string $name,
        public float $price,
        public int $stock = 0,
        public ?string $note = null,
    ) {
        if ($stock > 1000) {
            throw new \DomainException('stock exceeds 1000');
        }
    }
}

#[Input]
final class FromBodyAndRouteConflictInput
{
    public function __construct(
        #[FromBody('foo')]
        #[FromRoute]
        public string $foo,
    ) {
    }
}

#[Input]
final class OrderItemInput
{
    public function __construct(
        #[Validate('required|string')]
        public string $product,
        #[Validate('required|integer')]
        public int $quantity,
    ) {
    }
}

#[Input]
final class OrderInput
{
    public function __construct(
        #[Validate('required|string')]
        public string $name,
        /** @var OrderItemInput[]|null */
        #[Validate('nullable|array')]
        #[Items(OrderItemInput::class)]
        public ?array $items = null,
    ) {
    }
}

#[Input]
final class RegisteredGeneratorInput
{
    public function __construct(
        #[Generator(TestGeneratorWithDependency::class)]
        public string $id,
        #[Validate('required|string')]
        public string $name,
    ) {
    }
}

final class TestGeneratorWithDependency implements GeneratorInterface
{
    public function __construct(private readonly string $prefix)
    {
    }

    public function generate(array $options = []): string
    {
        return $this->prefix . '_generated';
    }
}

// ===== configuration-error fixtures =====

#[Input]
final class CastAndCasterConflictInput
{
    public function __construct(
        #[Cast('int')]
        #[Caster(CustomCaster::class)]
        public int $value,
    ) {
    }
}

#[Input]
final class CastWithClassInput
{
    public function __construct(
        #[Cast(CustomCaster::class)]
        public int $value,
    ) {
    }
}

#[Input]
final class DecimalCastOnIntInput
{
    public function __construct(
        #[Cast('decimal')]
        public int $value = 0,
    ) {
    }
}

#[Input]
final class UnknownCastInput
{
    public function __construct(
        #[Cast('unknown_type')]
        public string $value,
    ) {
    }
}

#[Input]
final class GeneratorWithFromRouteInput
{
    public function __construct(
        #[Generator(NullGenerator::class)]
        #[FromRoute]
        public string $id,
    ) {
    }
}

#[Input]
final class ItemsWithCastInput
{
    public function __construct(
        /** @var array<mixed> */
        #[Items(OrderItemInput::class)]
        #[Cast('array')]
        public array $items = [],
    ) {
    }
}

final class CountingGenerator implements GeneratorInterface
{
    public static int $calls = 0;

    public function generate(array $options = []): int
    {
        return ++self::$calls;
    }
}

final class CountingJsonNormalizer implements NormalizerInterface
{
    public static int $calls = 0;

    public function normalize(mixed $value, ProcessContext $context): mixed
    {
        self::$calls++;
        return is_string($value) ? json_decode($value, true) : $value;
    }
}

#[Input]
final class AddressInput
{
    public function __construct(
        #[Validate('required|string')]
        public string $city,
        #[Validate('required|string')]
        public string $zip,
    ) {
    }
}

#[Input]
class NodeInput
{
    public function __construct(
        public string $name = '',
        public ?self $next = null,
        #[FromContext('origin')]
        public ?self $origin = null,
    ) {
    }
}

// Inherited fields are passed on to the parent's constructor; their
// attributes and types stay on the parent's properties.
final class LeafNodeInput extends NodeInput
{
    public function __construct(
        string $name = '',
        ?NodeInput $next = null,
        ?NodeInput $origin = null,
        public ?parent $base = null,
        #[FromContext('root')]
        public ?parent $root = null,
    ) {
        parent::__construct($name, $next, $origin);
    }
}

#[Input]
final class InputKeyedInput
{
    public function __construct(
        #[FromBody('display_name')]
        #[Validate('required|string')]
        public string $name,
        #[FromRoute('product_id')]
        #[Validate('required|integer')]
        public int $productId,
        #[FromContext('authUserId')]
        #[Validate('required|integer')]
        public int $createdBy,
        /** @var OrderItemInput[] */
        #[FromBody('order.lines')]
        #[Items(OrderItemInput::class)]
        public array $lines = [],
    ) {
    }
}

#[Input]
final class BlankFieldsInput
{
    public function __construct(
        #[EmptyToNull]
        public string $code,
        #[EmptyToNull]
        public string $sort = 'id',
    ) {
    }
}

#[Input]
final class PatchAddressInput
{
    public function __construct(
        public ?string $city = null,
        public ?string $zip = null,
    ) {
    }
}

final class ArrayContainer implements ContainerInterface
{
    public int $gets = 0;

    /** @param array<string, mixed> $entries */
    public function __construct(private readonly array $entries)
    {
    }

    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new class ("No entry {$id}") extends \RuntimeException implements NotFoundExceptionInterface {
            };
        }
        $this->gets++;

        return $this->entries[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }
}

#[NoTrim]
#[Input]
final class NoTrimClassInput
{
    public function __construct(
        public ?string $a = null,
        #[EmptyToNull]
        public ?string $c = null,
    ) {
    }
}

#[Input]
final class UnionTypesInput
{
    public function __construct(
        public int|float $amount = 0,
        public int|float $ratio = 0,
        public int|string $code = 0,
        public int|bool $flag = false,
        /** @var array<mixed>|string */
        public array|string $tags = '',
    ) {
    }
}

#[Input]
final class ContextUnionInput
{
    public function __construct(
        #[FromContext('limit')]
        public int|float $limit,
    ) {
    }
}

final class NullGenerator implements GeneratorInterface
{
    public function generate(array $options = []): mixed
    {
        return null;
    }
}

#[Input]
final class NullGeneratedInput
{
    public function __construct(
        #[Generator(NullGenerator::class)]
        public string $token,
    ) {
    }
}

#[Input]
final class CollidingItemsInput
{
    public function __construct(
        /** @var list<CollidingItemInput> */
        #[Items(CollidingItemInput::class)]
        public array $items = [],
    ) {
    }
}

#[Input]
final class CollidingItemInput
{
    public function __construct(
        public ?int $code = null,
        #[FromBody('nested.code')]
        public ?bool $nestedCode = null,
    ) {
    }
}

#[Input]
final class LineItemInput
{
    public function __construct(
        public ?string $sku = null,
        public int $qty = 1,
    ) {
    }
}

#[Input]
final class PatchLinesInput
{
    public function __construct(
        /** @var list<LineItemInput> */
        #[Items(LineItemInput::class)]
        public array $lines = [],
    ) {
    }
}

#[Input]
final class ConditionalEmailInput
{
    public function __construct(
        public string $type = 'person',
        #[Validate('required_if:type,company|email')]
        public ?string $email = null,
        #[Validate('string|max:10')]
        public ?string $note = null,
    ) {
    }
}

#[Input]
final class SourcedInput
{
    public function __construct(
        #[FromRoute]
        public ?int $id = null,
        #[FromContext('authUserId')]
        public ?int $createdBy = null,
    ) {
    }
}

#[Input]
final class TermsInput
{
    public function __construct(
        public true $accepted,
        public string|false $nickname = false,
    ) {
    }
}

#[Input]
final class DecimalInput
{
    public function __construct(
        #[Cast('decimal')]
        public ?string $rate = null,
    ) {
    }
}

#[Input]
final class IterableInput
{
    public function __construct(
        /** @var iterable<mixed> */
        public iterable $tags = [],
    ) {
    }
}

#[Input]
final class ContextBeforeHandlersInput
{
    public function __construct(
        #[Generator(CountingGenerator::class)]
        public int $sequence,
        #[FromContext('tenant')]
        public string $tenant,
        /** @var list<string>|null */
        #[Normalize(CountingJsonNormalizer::class)]
        public ?array $tags = null,
        #[FromContext('address')]
        public ?AddressInput $address = null,
    ) {
    }
}

enum BlankFilter: string
{
    case All = '';
    case Active = 'active';
}

#[Input]
final class BlankFilterInput
{
    public function __construct(
        public ?BlankFilter $filter = null,
    ) {
    }
}

#[Input]
final class PostProcessedContextLinesInput
{
    public function __construct(
        /** @var list<LineItemInput> */
        #[FromContext('lines')]
        #[Items(LineItemInput::class)]
        #[PostProcess(PassThroughProcessor::class)]
        public array $lines = [],
    ) {
    }
}

#[Input]
final class PostProcessedChildrenInput
{
    public function __construct(
        /** @var list<LineItemInput> */
        #[PostProcess(PassThroughProcessor::class)]
        #[Items(LineItemInput::class)]
        public array $lines = [],
        #[PostProcess(PassThroughProcessor::class)]
        public ?PatchAddressInput $address = null,
    ) {
    }
}

/** @implements PostProcessorInterface<mixed> */
final class PassThroughProcessor implements PostProcessorInterface
{
    public function postProcess(mixed $value, ProcessContext $context): mixed
    {
        return $value;
    }
}
