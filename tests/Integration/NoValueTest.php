<?php

declare(strict_types=1);

namespace Solo\Input\Tests\Integration\NoValue;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Solo\Contracts\Validator\ValidatorInterface;
use Solo\Input\Attributes\{Cast, Caster, EmptyToNull, Input, Items, Normalize, Validate};
use Solo\Input\Attributes\Source\FromContext;
use Solo\Input\Contracts\{CasterInterface, NormalizerInterface};
use Solo\Input\Exceptions\{FieldValidationException, ValidationException};
use Solo\Input\{InputFactory, Inputs, ProcessContext};
use Solo\Validator\Validator;

/**
 * A field with no value — null, or a `""` its type cannot hold — is checked
 * by presence rules alone, against solophp/validator: the field then holds
 * null, keeps its default or reports `required`. A value is checked by every
 * rule.
 */
final class NoValueTest extends TestCase
{
    private InputFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new InputFactory(new Validator([
            'accepted' => static fn(mixed $value): bool => in_array($value, [true, '1', 'yes', 'on'], true),
        ]));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function noValues(): iterable
    {
        yield '""' => [''];
        yield '" "' => [' '];
        yield 'null' => [null];
    }

    #[DataProvider('noValues')]
    public function testAFilterWithNoValueIsNoFilter(mixed $none): void
    {
        $input = $this->factory->fromArray(TaskIndexInput::class, ['q' => $none, 'done' => $none]);

        self::assertNull($input->q);
        self::assertNull($input->done);
        self::assertSame(['q', 'done'], Inputs::provided($input));
    }

    public function testAQueryFilterIsCheckedOnlyWhenFilled(): void
    {
        $input = $this->factory->fromQuery(TaskIndexInput::class, $this->query(['q' => '', 'done' => '']));
        self::assertNull($input->q);
        self::assertNull($input->done);

        $this->assertErrors(
            ['q' => [['rule' => 'max', 'params' => ['100']]], 'done' => [['rule' => 'boolean']]],
            fn() => $this->factory->fromQuery(
                TaskIndexInput::class,
                $this->query(['q' => str_repeat('a', 101), 'done' => 'maybe']),
            ),
        );

        $input = $this->factory->fromQuery(TaskIndexInput::class, $this->query(['q' => ' tea ', 'done' => '1']));
        self::assertSame('tea', $input->q);
        self::assertTrue($input->done);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function typesThatHoldNoString(): iterable
    {
        yield 'int' => ['count'];
        yield 'float' => ['ratio'];
        yield 'bool' => ['flag'];
        yield 'decimal' => ['amount'];
        yield 'date' => ['day'];
        yield 'enum' => ['status'];
        yield 'union without string' => ['number'];
        yield 'array cast' => ['payload'];
        yield 'iterable' => ['entries'];
    }

    #[DataProvider('typesThatHoldNoString')]
    public function testABlankIsNoValueWhereTheTypeHoldsNoString(string $field): void
    {
        $input = $this->factory->fromArray(TypedInput::class, [$field => '']);

        self::assertSame([$field => null], Inputs::toArray($input, onlyProvided: true));
    }

    public function testAValueIsCheckedByEveryRule(): void
    {
        // "" for a string, [] and the "" case of an enum are values too.
        $this->assertErrors(
            [
                'text' => [['rule' => 'min', 'params' => ['1']]],
                'tags' => [['rule' => 'min', 'params' => ['1']]],
                'mode' => [['rule' => 'in', 'params' => ['fast']]],
            ],
            fn() => $this->factory->fromArray(TypedInput::class, ['text' => '', 'tags' => [], 'mode' => '']),
        );

        $this->assertErrors(
            [
                'page' => [['rule' => 'min_value', 'params' => ['1']]],
                'sort' => [['rule' => 'in', 'params' => ['created_at', 'name']]],
            ],
            fn() => $this->factory->fromArray(NonNullableInput::class, [
                'name' => 'a', 'page' => '0', 'sort' => 'size',
            ]),
        );

        $this->assertErrors(
            ['terms' => [['rule' => 'accepted']]],
            fn() => $this->factory->fromArray(TermsInput::class, ['terms' => 'no']),
        );
    }

    public function testABlankListIsNoValue(): void
    {
        $input = $this->factory->fromArray(ListInput::class, ['tags' => '', 'lines' => '', 'ids' => '']);
        self::assertNull($input->tags);
        self::assertSame([], $input->lines, 'its default; `min:1` checks an empty list, not no value');
        self::assertSame([], $input->ids, 'the caster never sees "", and its rules do not run');

        $this->assertErrors(
            ['lines' => [['rule' => 'required']]],
            fn() => $this->factory->fromArray(OrderInput::class, ['lines' => '']),
        );
    }

    public function testAnEmptyListIsAValue(): void
    {
        $this->assertErrors(
            ['lines' => [['rule' => 'min', 'params' => ['1']]]],
            fn() => $this->factory->fromArray(ListInput::class, ['lines' => []]),
        );
    }

    public function testACasterStillChecksWhatItReads(): void
    {
        self::assertSame(['a', 'b'], $this->factory->fromArray(ListInput::class, ['ids' => 'a, b'])->ids);

        $this->assertErrors(
            ['ids' => [['rule' => 'max', 'params' => ['20']]]],
            fn() => $this->factory->fromArray(ListInput::class, ['ids' => str_repeat('a', 21)]),
        );
    }

    public function testOtherFieldsSeeNoValueAsNull(): void
    {
        $this->assertErrors(
            ['reason' => [['rule' => 'required_if', 'params' => ['count', 'null']]]],
            fn() => $this->factory->fromArray(CrossFieldInput::class, ['count' => '']),
        );

        self::assertNull($this->factory->fromArray(CrossFieldInput::class, ['count' => '5'])->reason);
    }

    public function testANormalizerMayReturnNoValue(): void
    {
        $input = $this->factory->fromArray(TypedInput::class, ['limit' => 'none']);
        self::assertNull($input->limit);

        $this->assertErrors(
            ['limit' => [['rule' => 'min_value', 'params' => ['1']]]],
            fn() => $this->factory->fromArray(TypedInput::class, ['limit' => '0']),
        );
    }

    #[DataProvider('noValues')]
    public function testANonNullableFieldWithoutADefaultStillFails(mixed $none): void
    {
        $this->assertErrors(
            ['name' => [['rule' => 'required']]],
            fn() => $this->factory->fromArray(NonNullableInput::class, ['name' => $none]),
        );
    }

    #[DataProvider('noValues')]
    public function testANonNullableFieldWithADefaultKeepsIt(mixed $none): void
    {
        $input = $this->factory->fromArray(NonNullableInput::class, ['name' => 'a', 'page' => $none, 'sort' => $none]);

        self::assertSame(1, $input->page);
        self::assertSame('created_at', $input->sort);
    }

    public function testPresenceRulesStillCheckAFieldWithNoValue(): void
    {
        $this->assertErrors(
            [
                'reason' => [['rule' => 'required']],
                'company' => [['rule' => 'required_if', 'params' => ['type', 'company']]],
                'nickname' => [['rule' => 'filled']],
            ],
            fn() => $this->factory->fromArray(PresenceInput::class, [
                'reason' => '', 'comment' => '', 'type' => 'company', 'company' => '',
                'vat' => null, 'nickname' => ' ', 'registry' => '',
            ]),
        );

        $this->assertErrors(
            ['vat' => [['rule' => 'required_with', 'params' => ['company']]]],
            fn() => $this->factory->fromArray(PresenceInput::class, [
                'reason' => 'r', 'comment' => null, 'type' => 'company', 'company' => 'Acme', 'vat' => '',
            ]),
        );

        $this->assertErrors(
            [
                'comment' => [['rule' => 'present']],
                'registry' => [['rule' => 'prohibited_if', 'params' => ['type', 'individual']]],
            ],
            fn() => $this->factory->fromArray(PresenceInput::class, [
                'reason' => 'r', 'type' => 'individual', 'registry' => 'X',
            ]),
        );
    }

    public function testPresenceRulesLetNoValueThroughWhenTheyDo(): void
    {
        $input = $this->factory->fromArray(PresenceInput::class, [
            'reason' => 'r', 'comment' => '', 'company' => '', 'vat' => '', 'registry' => '',
        ]);

        self::assertSame(
            ['reason' => 'r', 'comment' => null, 'type' => 'individual', 'company' => null, 'vat' => null,
                'nickname' => null, 'registry' => null],
            Inputs::toArray($input),
        );
    }

    #[DataProvider('noValues')]
    public function testARuleOfYourOwnRunsOnNoValueOnlyAsAPresenceRule(mixed $none): void
    {
        $this->assertErrors(
            ['terms' => [['rule' => 'required']]],
            fn() => $this->factory->fromArray(TermsInput::class, ['terms' => $none, 'consent' => $none]),
        );

        $input = $this->factory->fromArray(TermsInput::class, ['terms' => 'yes', 'consent' => $none]);
        self::assertFalse($input->consent, '`accepted` alone does not run');
    }

    public function testAContextNullIsNoValueToo(): void
    {
        $input = $this->factory->fromArray(ContextInput::class, [], context: ['tenant' => 1, 'user' => null]);
        self::assertNull($input->user);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage("Context value 'tenant' is empty");
        $this->factory->fromArray(ContextInput::class, [], context: ['tenant' => null]);
    }

    public function testFilledKeepsAPartialFieldFromBeingCleared(): void
    {
        $input = $this->factory->fromArray(UpdateProductInput::class, ['note' => null]);
        self::assertSame(['note' => null], Inputs::toArray($input));

        $this->assertErrors(
            ['name' => [['rule' => 'filled']], 'active' => [['rule' => 'filled']]],
            fn() => $this->factory->fromArray(UpdateProductInput::class, ['name' => null, 'active' => '']),
        );

        self::assertSame([], Inputs::toArray($this->factory->fromArray(UpdateProductInput::class, [])));
    }

    public function testAnItemFieldWithNoValueReportsItsPath(): void
    {
        $this->assertErrors(
            ['lines.0.qty' => [['rule' => 'required']]],
            fn() => $this->factory->fromArray(OrderInput::class, [
                'lines' => [['qty' => '', 'note' => null], ['qty' => '2']],
            ]),
        );

        $input = $this->factory->fromArray(OrderInput::class, ['lines' => [['qty' => '2', 'note' => '']]]);
        self::assertNull($input->lines[0]->note);
    }

    public function testTheValidatorGetsNoValueAsNullAndTheRulesAsWritten(): void
    {
        $validator = new RecordingValidator();
        $factory = new InputFactory($validator);

        $factory->fromArray(TaskIndexInput::class, ['q' => '', 'done' => '', 'tag' => null]);
        $factory->fromArray(ListInput::class, ['tags' => '', 'lines' => '', 'ids' => '']);
        $factory->fromArray(TypedInput::class, ['text' => '', 'mode' => '']);

        self::assertSame([
            ['q' => null, 'done' => null, 'tag' => null],
            ['tags' => null, 'lines' => null, 'ids' => null],
            ['text' => '', 'mode' => ''],
        ], $validator->data);
        self::assertSame(
            ['q' => 'string|max:100', 'done' => 'boolean', 'tag' => 'nullable|string|max:100'],
            $validator->rules[0],
        );
    }

    #[DataProvider('noValues')]
    public function testARedundantNullableKeepsWorking(mixed $none): void
    {
        self::assertNull($this->factory->fromArray(TaskIndexInput::class, ['tag' => $none])->tag);
    }

    /**
     * Nullable fields, and what a `null` gives: the field, or its errors.
     *
     * @return iterable<string, array{class-string, string, array<string, mixed>}>
     */
    public static function nullsAndTheSchema(): iterable
    {
        yield 'filter' => [TaskIndexInput::class, 'done', ['done' => null]];
        yield 'filter with nullable' => [TaskIndexInput::class, 'tag', ['tag' => null]];
        yield 'clearable' => [UpdateProductInput::class, 'note', ['note' => null]];
        yield 'filled' => [UpdateProductInput::class, 'name', ['name' => [['rule' => 'filled']]]];
    }

    /**
     * @param class-string         $class
     * @param array<string, mixed> $expected
     */
    #[DataProvider('nullsAndTheSchema')]
    public function testTheSchemaSaysWhetherAFieldTakesNull(string $class, string $key, array $expected): void
    {
        $field = $this->factory->schema($class)[$key];
        self::assertTrue($field['nullable']);
        self::assertSame($expected[$key] !== null, in_array('filled', array_column($field['rules'], 'name'), true));

        try {
            $result = Inputs::toArray($this->factory->fromArray($class, [$key => null]), onlyProvided: true);
        } catch (ValidationException $e) {
            $result = $e->getErrors();
        }
        self::assertSame($expected, $result);
    }

    public function testTheSchemaKeepsTheDeclaredRules(): void
    {
        $schema = $this->factory->schema(TaskIndexInput::class);

        self::assertSame(
            [['name' => 'string', 'args' => []], ['name' => 'max', 'args' => ['100']]],
            $schema['q']['rules'],
        );
        self::assertSame([['name' => 'boolean', 'args' => []]], $schema['done']['rules']);
        self::assertTrue($schema['q']['nullable']);
        self::assertFalse($schema['q']['required']);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function query(array $query): ServerRequestInterface
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn($query);

        return $request;
    }

    /**
     * @param array<string, list<array{rule: string, params?: list<string>}>> $expected
     * @param \Closure(): object                                               $build
     */
    private function assertErrors(array $expected, \Closure $build): void
    {
        try {
            $build();
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame($expected, $e->getErrors());
        }
    }
}

#[Input]
final readonly class TaskIndexInput
{
    public function __construct(
        #[Validate('string|max:100')]
        #[EmptyToNull]
        public ?string $q = null,
        #[Validate('boolean')]
        public ?bool $done = null,
        #[Validate('nullable|string|max:100')]
        #[EmptyToNull]
        public ?string $tag = null,
    ) {
    }
}

enum Status: string
{
    case Open = 'open';
    case Closed = 'closed';
}

enum Mode: string
{
    case Default = '';
    case Fast = 'fast';
}

final class NoneToNull implements NormalizerInterface
{
    public function normalize(mixed $value, ProcessContext $context): mixed
    {
        return $value === 'none' ? null : $value;
    }
}

#[Input]
final readonly class TypedInput
{
    /**
     * @param list<mixed>|null     $tags
     * @param iterable<mixed>|null $entries
     */
    public function __construct(
        #[Validate('integer')]
        public ?int $count = null,
        #[Validate('numeric')]
        public ?float $ratio = null,
        #[Validate('boolean')]
        public ?bool $flag = null,
        #[Validate('numeric')]
        #[Cast('decimal')]
        public ?string $amount = null,
        #[Validate('date')]
        public ?DateTimeImmutable $day = null,
        #[Validate('in:open,closed')]
        public ?Status $status = null,
        #[Validate('string|min:1')]
        public ?string $text = null,
        #[Validate('array|min:1')]
        public ?array $tags = null,
        #[Validate('integer|min_value:1')]
        #[Normalize(NoneToNull::class)]
        public ?int $limit = null,
        #[Validate('in:fast')]
        public ?Mode $mode = null,
        #[Validate('numeric')]
        public int|float|null $number = null,
        #[Validate('array')]
        #[Cast('array')]
        public mixed $payload = null,
        #[Validate('array')]
        public ?iterable $entries = null,
    ) {
    }
}

/** @implements CasterInterface<list<string>> */
final class SplitCaster implements CasterInterface
{
    public function cast(mixed $value, ProcessContext $context): mixed
    {
        if (!is_string($value)) {
            throw new FieldValidationException('string');
        }

        return array_map('trim', explode(',', $value));
    }
}

#[Input]
final readonly class ListInput
{
    /**
     * @param list<mixed>|null $tags
     * @param list<LineInput>  $lines
     * @param list<string>     $ids
     */
    public function __construct(
        #[Validate('array')]
        public ?array $tags = null,
        #[Validate('array|min:1')]
        #[Items(LineInput::class)]
        public array $lines = [],
        #[Validate('string|max:20')]
        #[Caster(SplitCaster::class)]
        public array $ids = [],
    ) {
    }
}

#[Input]
final readonly class CrossFieldInput
{
    public function __construct(
        #[Validate('integer')]
        public ?int $count = null,
        #[Validate('string|required_if:count,null')]
        public ?string $reason = null,
    ) {
    }
}

#[Input]
final readonly class NonNullableInput
{
    public function __construct(
        #[Validate('string|max:255')]
        #[EmptyToNull]
        public string $name,
        #[Validate('integer|min_value:1')]
        public int $page = 1,
        #[Validate('in:created_at,name')]
        #[EmptyToNull]
        public string $sort = 'created_at',
    ) {
    }
}

#[EmptyToNull]
#[Input]
final readonly class PresenceInput
{
    public function __construct(
        #[Validate('required|string')]
        public ?string $reason,
        #[Validate('present|string')]
        public ?string $comment,
        #[Validate('in:individual,company')]
        public string $type = 'individual',
        #[Validate('string|required_if:type,company')]
        public ?string $company = null,
        #[Validate('string|required_with:company')]
        public ?string $vat = null,
        #[Validate('filled|string')]
        public ?string $nickname = null,
        #[Validate('string|prohibited_if:type,individual')]
        public ?string $registry = null,
    ) {
    }
}

#[Input]
final readonly class TermsInput
{
    public function __construct(
        #[Validate('required|accepted')]
        public bool $terms,
        #[Validate('accepted')]
        public bool $consent = false,
    ) {
    }
}

#[Input]
final readonly class ContextInput
{
    public function __construct(
        #[FromContext('tenant')]
        #[Validate('integer')]
        public int $tenant,
        #[FromContext('user')]
        #[Validate('integer')]
        public ?int $user = null,
    ) {
    }
}

#[Input(partial: true)]
final readonly class UpdateProductInput
{
    public function __construct(
        #[Validate('filled|string|max:255')]
        public ?string $name = null,
        #[Validate('string|max:1000')]
        public ?string $note = null,
        #[Validate('filled|boolean')]
        public ?bool $active = null,
    ) {
    }
}

#[Input]
final readonly class LineInput
{
    public function __construct(
        #[Validate('integer|min_value:1')]
        public int $qty,
        #[Validate('string|max:50')]
        #[EmptyToNull]
        public ?string $note = null,
    ) {
    }
}

#[Input]
final readonly class OrderInput
{
    /**
     * @param list<LineInput> $lines
     */
    public function __construct(
        #[Items(LineInput::class)]
        public array $lines,
    ) {
    }
}

final class RecordingValidator implements ValidatorInterface
{
    /** @var list<array<string, mixed>> */
    public array $data = [];

    /** @var list<array<string, string>> */
    public array $rules = [];

    public function validate(array $data, array $rules): array
    {
        $this->data[] = $data;
        $this->rules[] = $rules;

        return [];
    }
}
