# Exceptions

The library provides `ValidationException` for aggregated input errors,
`FieldValidationException` for a normalizer or caster rejecting its field,
and `ConfigurationException` for a misconfigured request class.

## ValidationException

Thrown when request data fails validation.

```php
namespace Solo\Input\Exceptions;

final class ValidationException extends Exception
{
    public function __construct(array $errors = [], ?\Throwable $previous = null);
    public function getErrors(): array;
}
```

### Properties

| Property | Type | Description |
|----------|------|-------------|
| `$message` | `string` | `"Validation failed: field1, field2"` |
| `$code` | `int` | `422` |

### Methods

#### getErrors()

Returns validation errors grouped by input path. Errors produced by
`InputFactory` follow property declaration order, with nested errors at the
parent property's position. Messages sharing a path are appended without
deduplication; their `rule` and optional `params` are preserved. See
[error order and overlapping paths](/features/validation#error-order-and-overlapping-paths).

```php
public function getErrors(): array<string, list<array{rule: string, params?: string[]}>>
```

**Example:**

```php
use Solo\Input\Exceptions\ValidationException;

try {
    $dto = $factory->fromBody(UserInput::class, $request);
} catch (ValidationException $e) {
    $errors = $e->getErrors();
    // [
    //     'email' => [['rule' => 'required'], ['rule' => 'email']],
    //     'age' => [['rule' => 'min', 'params' => ['18']]],
    // ]

    return $this->json(['errors' => $errors], 422);
}
```

### Usage Pattern

```php
class UserController
{
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $dto = $this->handler->fromBody(CreateUserInput::class, $request);
            $this->userService->create($dto);
            return $this->json(['status' => 'created'], 201);

        } catch (ValidationException $e) {
            // Client error - invalid input
            return $this->json([
                'message' => 'Validation failed',
                'errors' => $e->getErrors()
            ], 422);
        }
    }
}
```

---

## ConfigurationException

Thrown when an input class has invalid configuration. This is a **developer error** caught at metadata-build time — before any input is processed — so misconfigurations surface in dev/test, not on a live request.

```php
namespace Solo\Input\Exceptions;

final class ConfigurationException extends Exception { /* one named factory per misconfiguration */ }
```

### Error types

Each factory below corresponds to one diagnosable misconfiguration. The message always names the offending class or `Class::$property` and points at the fix.

#### notAnInput

```php
// ❌ Built, nested or listed in #[Items] without the marker
final readonly class CreateUserInput
{
    public function __construct(public string $name) {}
}

// ✅
#[Input]
final readonly class CreateUserInput
{
    public function __construct(public string $name) {}
}
```

#### notInstantiable

An input class must be concrete with a public constructor: the factory calls it.

#### parameterWithoutField

```php
// ❌ A constructor parameter no public property holds
#[Input]
final readonly class PageInput
{
    public function __construct(int $page = 1) {}
}

// ✅ Promote it
#[Input]
final readonly class PageInput
{
    public function __construct(public int $page = 1) {}
}
```

A private or protected promoted property, and a variadic parameter, fail the same way.

#### notAField

```php
// ❌ A field attribute on a property the constructor does not take — it would never run
#[Input]
final class ProfileInput
{
    #[Validate('string')]
    public string $nickname = '';

    public function __construct(public string $name) {}
}

// ✅ Make it a constructor parameter
#[Input]
final readonly class ProfileInput
{
    public function __construct(
        public string $name,
        #[Validate('string')]
        public string $nickname = '',
    ) {}
}
```

#### nullableRuleWithNonNullableType

```php
// ❌ 'nullable' rule on non-nullable type
#[Validate('nullable|email')]
public string $email,

// ✅ Make the type nullable
#[Validate('nullable|email')]
public ?string $email = null,
```

#### castTypeMismatch

```php
// ❌ Cast produces a different type than the property accepts
#[Cast('string')]
public int $id,

// ✅
#[Cast('int')]
public int $id,
```

#### requiredWithDefault

```php
// ❌ 'required' and a default contradict each other
#[Validate('required|string')]
public string $name = 'default',

// ✅
#[Validate('required|string')]
public string $name,
```

#### castAndCasterConflict

```php
// ❌ Both #[Cast] and #[Caster] on one property
#[Cast('int')]
#[Caster(MyCaster::class)]
public int $value,

// ✅ Pick one
#[Caster(MyCaster::class)]
public int $value,
```

#### unknownCastType

```php
// ❌ Not a recognised built-in
#[Cast('unknown_type')]
public string $value,

// ✅
#[Cast('string')]
public string $value,
```

#### castExpectsBuiltInType

```php
// ❌ #[Cast] received a class name
#[Cast(MyCaster::class)]
public string $value,

// ✅ For custom casters, use #[Caster]
#[Caster(MyCaster::class)]
public string $value,
```

#### dateFormatRequiresDateType

```php
// ❌ #[DateFormat] on a type that cannot hold a date
#[DateFormat('Y-m-d')]
public string $date,

// ✅ DateTimeImmutable, DateTime, DateTimeInterface — or an untyped / mixed property
#[DateFormat('Y-m-d')]
public DateTimeImmutable $date,
```

#### dateFormatConflict

`#[DateFormat]` reads the date itself, so `#[Cast]`, `#[Caster]` and `#[Items]` cannot join it.

```php
// ❌
#[DateFormat('Y-m-d')]
#[Caster(DateCaster::class)]
public DateTimeImmutable $date,

// ✅ Pick one
#[DateFormat('Y-m-d')]
public DateTimeImmutable $date,
```

#### invalidHandler

`#[Normalize]`, `#[Caster]`, `#[PostProcess]` and `#[Generator]` require a class implementing `NormalizerInterface`, `CasterInterface`, `PostProcessorInterface` or `GeneratorInterface`, respectively. Missing classes, functions, method-name strings and classes implementing a different handler interface are rejected during metadata building. An abstract class or an interface is accepted: whether it can be built is decided on first use (see [handlerNotInstantiable](#handlernotinstantiable)).

```php
// Invalid: a function is not a normalizer class.
#[Normalize('trim')]
public string $name,

// Correct: TrimNormalizer implements NormalizerInterface.
#[Normalize(TrimNormalizer::class)]
public string $name,
```

#### unknownAttribute

An attribute in the `Solo\Input\Attributes\` namespace that the library does not read where it stands — a misspelled name, a wrong namespace, a property attribute on a class — fails metadata building instead of being ignored. When a known attribute has the same short name, the message suggests it.

```php
// ❌ Source attributes live in Attributes\Source: this class does not exist,
//    and without the check the property would be read from the request body.
use Solo\Input\Attributes\FromContext;

#[FromContext('authUserId')]
public int $createdBy,

// ✅
use Solo\Input\Attributes\Source\FromContext;
```

#### invalidItems

```php
// ❌ Class missing, or not an #[Input] class
#[Items('NonExistentClass')]
public ?array $items = null,

// ✅
#[Items(OrderItemInput::class)]
public ?array $items = null,
```

#### itemsRequiresArrayType

```php
// ❌ #[Items] on a non-array property
#[Items(OrderItemInput::class)]
public string $items,

// ✅
#[Items(OrderItemInput::class)]
public ?array $items = null,
```

#### itemsCastConflict

`#[Items]` skips automatic casting (each element is processed through its own DTO), so `#[Cast]` / `#[Caster]` would have no effect.

```php
// ❌
#[Items(OrderItemInput::class)]
#[Cast('array')]
public array $items,

// ✅
#[Items(OrderItemInput::class)]
public array $items = [],
```

#### generatorConflict

Generated values bypass the input/validation pipeline, so any companion attribute (`#[Validate]`, `#[Cast]`, `#[FromX]`, `#[Items]`, …) would never run.

```php
// ❌
#[Generator(UuidGenerator::class)]
#[Validate('required|string')]
public string $id,

// ✅ Use one or the other
#[Generator(UuidGenerator::class)]
public string $id,
```

#### multipleSources

```php
// ❌ Two source attributes on one property
#[FromRoute]
#[FromContext('value')]
public string $value,

// ✅ Pick one
#[FromRoute]
public string $value,
```

#### unproducibleType

A class-typed field must be producible from request input: an `#[Input]` class (nested DTO), a `BackedEnum`, a DateTime class — or carry `#[Caster]`, `#[Generator]`, or read `#[FromContext]`.

```php
// ❌ A string from the request can never become a Money
public Money $price,

// ✅
#[Caster(MoneyCaster::class)]
public Money $price,

// ✅ The application supplies the object
#[FromContext('currentUser')]
public User $user,
```


#### abstractClass

`#[Items]` and nested-DTO classes need a concrete instantiable class: the factory builds them itself.

```php
// ❌
#[Items(AbstractItemInput::class)]
public ?array $items = null,

// ✅ Pass a concrete subclass
#[Items(OrderItemInput::class)]
public ?array $items = null,
```

#### handlerNotInstantiable

Thrown on first use — not at build time — because a normalizer, caster, post-processor or generator that is an interface, an abstract class or a class needing constructor arguments is valid configuration once the container provides it or an instance is registered for it.

```php
// ❌ CurrencyCaster requires a CurrencyConverter, nothing provides one
#[Caster(CurrencyCaster::class)]
public Money $price,

// ✅ The container builds it
$factory = new InputFactory($validator, container: $container);

// ✅ Or register an instance
$factory->register(CurrencyCaster::class, new CurrencyCaster($converter));
```

#### containerEntryMismatch

Thrown on first use when the container's entry for a handler class is not an instance of that class — say, an alias pointing at the wrong service.

### Usage Pattern

```php
try {
    $dto = $factory->fromBody(BrokenInput::class, $request);
} catch (ConfigurationException $e) {
    // Developer error - fix the input class
    error_log('Configuration error: ' . $e->getMessage());
    return $this->json(['error' => 'Internal Server Error'], 500);
} catch (ValidationException $e) {
    // Client error - invalid input
    return $this->json(['errors' => $e->getErrors()], 422);
}
```

---

## Exception Hierarchy

```
Exception
├── ValidationException      (422 - Client error, invalid input)
├── ConfigurationException   (500 - Developer error, fix DTO)
├── LogicException           (500 - Handler rejects input during the wrong stage)
└── RuntimeException
    ├── FieldValidationException (Internal field rejection, aggregated by the factory)
    └── UnexpectedValueException (500 - Incompatible application/handler value)
```

---

## Best Practices

### Catch Specific Exceptions

```php
try {
    $dto = $factory->fromBody(MyInput::class, $request);
    $this->service->process($dto);
    return $this->json(['status' => 'ok']);

} catch (ValidationException $e) {
    // 422 - Tell client what's wrong
    return $this->json(['errors' => $e->getErrors()], 422);

} catch (ConfigurationException $e) {
    // 500 - Log and hide details from client
    $this->logger->error('DTO configuration error', [
        'exception' => $e->getMessage()
    ]);
    return $this->json(['error' => 'Internal error'], 500);
}
```

### Don't Catch ConfigurationException in Production

Configuration errors should be caught during development/testing:

```php
// In development - let it bubble up
$dto = $factory->fromBody(MyInput::class, $request);

// In production - only catch ValidationException
try {
    $dto = $factory->fromBody(MyInput::class, $request);
} catch (ValidationException $e) {
    return $this->json(['errors' => $e->getErrors()], 422);
}
// ConfigurationException will trigger error handler → 500
```

## FieldValidationException

Normalizers and casters reject the current field with `new FieldValidationException('rule', params: [...], previous: $cause)`. Parameters are a list of strings; both optional arguments may be omitted. The factory supplies the input path and aggregates the error into the public `ValidationException`. The same rejection on a context value is an `UnexpectedValueException` with the field exception as its cause.

## Handler Contract Errors

A normalizer or caster throwing the aggregate `ValidationException` directly raises `LogicException`; use `FieldValidationException` instead. A post-processor or generator throwing either validation exception also raises `LogicException`: input checks must finish during resolution. The original exception is retained as `getPrevious()`.

Caster and post-processor results are checked strictly against the field type and any `#[Items]` item class. A wrong result throws `UnexpectedValueException`, even if PHP could coerce it or the field has a default. Other application exceptions — one the input class's constructor throws included — propagate normally.
