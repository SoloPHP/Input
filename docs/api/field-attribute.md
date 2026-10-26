# Attributes API

14 attribute classes organised into two namespaces. See [Attributes (feature guide)](/features/field-attribute) for usage patterns and examples.

`#[Input]` targets the class. Every other attribute targets `Attribute::TARGET_PROPERTY` (`#[EmptyToNull]` and `#[NoTrim]` also `TARGET_CLASS`) and is not repeatable — at most one of each per field. On a promoted constructor parameter PHP applies it to the property, where it is read.

---

## `#[Input]` — `Solo\Input\Attributes\Input`

```php
#[Attribute(Attribute::TARGET_CLASS)]
final class Input
{
    public function __construct(public bool $partial = false) {}
}
```

Marks an input class. `InputFactory` builds it through its constructor, a field typed with it is a nested DTO, and `#[Items]` accepts it. Its constructor parameters are its fields: each needs a public property of the same name — promoted, or inherited from a parent constructor. A field with a default is optional; one without fails `required` when absent. The marker on a parent class applies to its subclasses. Building a class without it throws `ConfigurationException::notAnInput`.

`partial: true` marks the body of a partial update (PATCH). An absent field still reads as its default, but [`Inputs::toArray()`](/api/input-factory#toarray) leaves it out unless called with `onlyProvided: false` — the array is the change set, so a default never overwrites what is stored. A subclass without a marker of its own keeps the parent's flag; one with `#[Input]` decides for itself.

---

## Source attributes — `Solo\Input\Attributes\Source\*`

Source attributes are optional. Without one, the source defaults to whatever the factory method dictates (Body for `fromBody`/`fromArray`, Query for `fromQuery`). Declaring more than one source attribute on the same field throws `ConfigurationException::multipleSources`.

### `#[FromRoute(?string $key = null)]`

```php
namespace Solo\Input\Attributes\Source;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class FromRoute
{
    public function __construct(public ?string $key = null) {}
}
```

Reads from the `$route` argument passed to `from*()`. Flat keys only — dots are part of the literal key name. Defaults to the field name when `null`. Empty-string key throws `InvalidArgumentException`.

### `#[FromContext(string $key)]`

```php
final class FromContext
{
    public function __construct(public string $key) {}
}
```

Reads from the `$context` argument passed to `from*()`. `$key` is required and non-empty.

### `#[FromBody(string $path)]`

```php
final class FromBody
{
    public function __construct(public string $path) {}
}
```

Reads from the body bag via a dot-path (`'customer.id'`, `'address.city'`). A single-segment path acts as a flat key remap (`#[FromBody('display_name')]` on `public string $name`). Validation errors for the property are reported under the path (`customer.id`, `display_name`).

Malformed paths (leading/trailing dot, empty segment like `'a..b'`) throw `InvalidArgumentException`. A dot-path that stops at a sub-object while the property is a scalar fails the property's cast like any other array (`integer`, `numeric`, `boolean` or `string`) — unless a normalizer or custom caster converts the array, or `#[Cast('json')]` encodes it.

---

## Pipeline attributes — `Solo\Input\Attributes\*`

### `#[Validate(string $rules)]`

```php
namespace Solo\Input\Attributes;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Validate
{
    public function __construct(public string $rules) {}
}
```

Rules are passed verbatim to `ValidatorInterface::validate()`. The factory does not transform rule strings; cross-field references (e.g. `unique:users,email,{id}`) are the validator's concern, and a typical implementation resolves `{id}` against the validation payload — which contains every field present in the request, including those marked `#[FromRoute]` / `#[FromContext]` and those without rules of their own. Empty rules throw `InvalidArgumentException`.

### `#[Cast(string $type)]`

```php
final class Cast
{
    public function __construct(public string $type) {}
}
```

Built-in cast: `int`, `float`, `bool`, `string`, `array`, `json` or `decimal` (case-insensitive). Needed only where the declared type does not imply one — a union or `mixed` property — and for `json`, which encodes a structured value into a `string` (or untyped / `mixed`) property, a string being kept as it is, and `decimal`, which writes a number into such a property in plain decimal notation (see [Type Casting](/features/type-casting#decimal)).

Passing a class name throws `ConfigurationException::castExpectsBuiltInType` at metadata-build time — use `#[Caster]` for custom logic. Other unknown strings throw `ConfigurationException::unknownCastType`. Dates are not a cast: a date property's class follows its type, with `#[DateFormat]` for a fixed format. Mutually exclusive with `#[Caster]`, `#[DateFormat]` and `#[Items]`.

### `#[DateFormat(string $format)]`

```php
final class DateFormat
{
    public function __construct(public string $format) {}
}
```

Reads the property's date with `createFromFormat($format)`. The class comes from the declared type (`DateTimeImmutable` / `DateTimeInterface` / untyped / `mixed` → `DateTimeImmutable`, `DateTime` → `DateTime`); any other type throws `ConfigurationException::dateFormatRequiresDateType`. A format without time-of-day fields yields midnight. An empty format throws `InvalidArgumentException`; combining it with `#[Cast]`, `#[Caster]` or `#[Items]` throws `ConfigurationException::dateFormatConflict`.

### `#[Caster(class-string<CasterInterface> $class, array $config = [])]`

```php
final class Caster
{
    public function __construct(public string $class, public array $config = []) {}
}
```

Custom caster — `$class` must implement `CasterInterface`. A missing or non-implementing class throws `ConfigurationException::invalidHandler`. Mutually exclusive with `#[Cast]` and `#[Items]`. Its `cast(mixed $value, ProcessContext $context)` runs during input resolution. Results must fit the property strictly; incorrect results throw `UnexpectedValueException`. Reject the current input field with `FieldValidationException`. Configuration is available as `$context->config`.

### `#[EmptyToNull]`

```php
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
final class EmptyToNull {}
```

A blank string (`""` or only whitespace, also with auto-trim off) becomes `null` after normalization and before validation: the field is then `null` when nullable, its default when it has one, and a `required` error otherwise. Non-string input is untouched. On a class, it applies to every field of the class and of its subclasses that the client sends — `#[FromContext]` and generated ones aside. Combining it with `#[Generator]` throws `ConfigurationException::generatorConflict`.

### `#[NoTrim]`

```php
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
final class NoTrim {}
```

Auto-trim skips the field: its string is kept as sent. On a class, it applies to every field of the class and of its subclasses (auto-trim never touches `#[FromContext]` values). No effect when the factory was built with `autoTrim: false`. Combining it with `#[Generator]` throws `ConfigurationException::generatorConflict`.

### `#[Normalize(string $class, array $config = [])]`

`$class` must implement `NormalizerInterface`. `normalize(mixed $value, ProcessContext $context): mixed` runs on every present value, including null and empty string, after auto-trim and before `#[EmptyToNull]` and validation. `$context->config` contains `config`.

### `#[PostProcess(string $class, array $config = [])]`

`$class` must implement `PostProcessorInterface`. `postProcess(mixed $value, ProcessContext $context): mixed` receives a non-null decoded value after the whole tree passes validation and casting. Nested values are complete DTOs. It does not replace casting or reject input. Incorrect results throw `UnexpectedValueException`; validation exceptions here become `LogicException`.

Functions and method-name strings are not accepted. Missing/wrong handler interfaces fail metadata construction with `ConfigurationException::invalidHandler`. See [Processing stages](/features/processors).

### `#[Generator(class-string<GeneratorInterface> $class, array $options = [])]`

```php
final class Generator
{
    public function __construct(
        public string $class,
        /** @var array<string, mixed> */
        public array $options = [],
    ) {}
}
```

`$class` must implement `GeneratorInterface`; a missing or non-implementing class throws `ConfigurationException::invalidHandler`. The property is **not** read from any source — the generator produces the value. `$options` are passed to `generate()`.

Mutually exclusive with `#[Validate]`, `#[Normalize]`, `#[PostProcess]`, `#[Cast]`, `#[Caster]`, `#[Items]`, and every source attribute (throws `ConfigurationException::generatorConflict`).

### `#[Items(class-string $class)]`

```php
final class Items
{
    public function __construct(public string $class) {}
}
```

`$class` must be an `#[Input]` class. The field must be typed `array` or `?array`. Each element of the input array is processed through `$class`. Auto-casting is skipped on items fields.

Mutually exclusive with `#[Cast]`, `#[Caster]`, `#[Generator]`. `$route` is NOT propagated into nested items — `$context` IS.

### `#[Group(string $name, ?string $mapTo = null)]`

```php
final class Group
{
    public function __construct(
        public string $name,
        public ?string $mapTo = null,
    ) {}
}
```

Marks the field as a member of group `$name`. `$mapTo` optionally remaps the output key when `Inputs::group($input, $name)` is built — a column name or a criteria key such as `'price >='`. See [Field Grouping](/features/grouping).

### `#[Exclude]`

```php
final class Exclude {}
```

The field is processed normally but omitted from `Inputs::toArray()`; `Inputs::group()` still sees it. Use for sensitive fields (passwords, tokens).

---

## Field source enum — `Solo\Input\FieldSource`

```php
enum FieldSource
{
    case Body;
    case Query;
    case Route;
    case Context;
}
```

Stored in the field metadata and used by `InputFactory` to dispatch to the correct bag at lookup time.

---

## Composition

Attributes compose freely; a typical "rich" field uses 2–3:

```php
use Solo\Input\Attributes\{Validate, Cast, Normalize, PostProcess, Generator, Items, Group, Exclude};
use Solo\Input\Attributes\Source\{FromRoute, FromContext};

#[FromRoute]
#[Validate('required|integer|exists:products,id')]
public int $id,

#[Validate('required|integer|min_value:1')]
#[Cast('int')]
public int $quantity,

#[Validate('array|min:1')]
#[Items(OrderItemInput::class)]
public array $items,

#[Generator(UuidGenerator::class)]
public string $revisionId,

#[Validate('required|string|min:8')]
#[Exclude]
public string $password,

#[FromContext('authUserId')]
#[Validate('required|integer')]
public int $updatedBy,

```
