# Attributes

The package uses a family of small, focused attributes. Each one carries a single concern; combine as many as you need on one field.

There are three groups:

1. **The class marker** `#[Input]` (under `Solo\Input\Attributes`) — the class is an input DTO, and its constructor parameters are its fields.
2. **Source attributes** (under `Solo\Input\Attributes\Source\*`) — declare WHERE the value comes from. Optional; without one the field reads from the default-source bag.
3. **Pipeline attributes** (under `Solo\Input\Attributes\*`) — describe what to do with the value (validate, cast, transform, generate, group, exclude).

Field attributes go on the constructor parameters; PHP applies them to the promoted properties, where the factory reads them.

## Overview

### Source

| Attribute | Source bag | Key default | Notes |
|---|---|---|---|
| `#[FromRoute(?string $key = null)]` | `$route` argument | field name | flat key |
| `#[FromContext(string $key)]` | `$context` argument | required | flat key |
| `#[FromBody(string $path)]` | body bag | required | dot-path; single-segment values act as a flat key remap |

Without any source attribute, the field uses the default source dictated by the factory method invoked (`fromBody` / `fromArray` → Body, `fromQuery` → Query). Declaring more than one source attribute on the same field throws `ConfigurationException::multipleSources` at metadata build time.

For values that don't fit the four bags (Body, Query, Route, Context) — request headers, cookies, file uploads — extract them in your controller and pass them via `route: [...]` or `context: [...]`.

### Pipeline

| Attribute | Purpose |
|---|---|
| `#[Validate('rules')]` | Validation rules passed to your `ValidatorInterface` |
| `#[Cast('int'\|'float'\|'bool'\|'string'\|'array'\|'json'\|'decimal')]` | Explicit cast for union / `mixed` properties; `json` encodes into a string, `decimal` writes a number into one in plain notation |
| `#[DateFormat('Y-m-d')]` | Fixed input format for a date property (class from the type) |
| `#[Caster(MoneyCaster::class)]` | Custom `CasterInterface` implementation |
| `#[EmptyToNull]` | A blank string is no value (`null` / default / `required`); also on a class |
| `#[NoTrim]` | Auto-trim skips the field — the string is kept as sent; also on a class |
| `#[Normalize(class, config: [...])]` | Normalize present raw input before validation |
| `#[PostProcess(class, config: [...])]` | Transform decoded values after the whole tree is valid |
| `#[Generator(GenClass::class, options: [...])]` | Value is generated, not read from any source |
| `#[Items(NestedInput::class)]` | The field is a list of nested DTOs |
| `#[Group('name', mapTo: '...')]` | Group membership + remap for `Inputs::group()` |
| `#[Exclude]` | `Inputs::toArray()` skips this field |

A parameter without attributes (`public string $name`) is read from the default-source bag by name and cast by its type — no validation, no handlers.

---

## Source attributes

### `#[FromRoute(?string $key = null)]`

Read from the `$route` argument passed to `from*()` — typically URL path parameters:

```php
// PUT /products/{id}
#[FromRoute]                                            // key = 'id'
#[Validate('required|integer|exists:products,id')]
public int $id,

#[FromRoute('product_id')]                              // explicit key
public int $productId,
```

### `#[FromContext(string $key)]`

Read from the `$context` argument — for application-supplied values that aren't part of the HTTP request itself (auth user, tenant, feature flags):

```php
#[FromContext('authUserId')]
#[Validate('required|integer')]
public int $createdBy,
```

### `#[FromBody(string $path)]`

Read from a nested key inside the body via a dot-path:

```php
// Body: {"customer": {"id": 42, "name": "Alice"}}
#[FromBody('customer.id')]
#[Validate('required|integer')]
public int $customerId,
```

A single-segment path acts as a flat key remap:

```php
// Body: {"display_name": "Alice"}
#[FromBody('display_name')]
public string $name,
```

Malformed paths (leading/trailing dot, empty segment) throw `InvalidArgumentException` at construction. If the path stops at a sub-object while the property is a scalar, the property's cast fails like for any other array — `['field' => [['rule' => 'integer']]]` for an `int`, `string` for a `string` — unless a normalizer or custom caster converts the array, or `#[Cast('json')]` encodes it.

---

## Pipeline attributes

### `#[Validate('rules')]`

Rules are passed verbatim to your `ValidatorInterface::validate()`. The factory does not transform rule strings — cross-field references (e.g. `unique:users,email,{id}`) are the validator's concern, and a typical implementation resolves `{id}` against the validation payload, which contains every field present in the input (including those marked `#[FromRoute]` / `#[FromContext]`).

```php
#[Validate('required|string|max:255')]
public string $name,

#[FromRoute]
#[Validate('required|integer|exists:users,id')]
public int $id,

#[Validate('required|email|unique:users,email,{id}')]
public string $email,
```

See [Validation](/features/validation).

### `#[Cast('type')]` and `#[Caster(class)]`

Without either attribute, casting is driven by the property's declared type: `int`, `float`, `bool`, `string`, `array`, the DateTime family and backed enums.

`#[Cast]` takes the built-in types only — `int`, `float`, `bool`, `string`, `array`, `json` to encode structured input into a string, and `decimal` to write a number into one in plain decimal notation — and is needed only where the type does not imply the cast. Dates follow their type; `#[DateFormat]` fixes the input format. Passing a class name throws `ConfigurationException::castExpectsBuiltInType` — use `#[Caster]` for custom logic.

```php
#[Cast('float')]
public int|float $amount,

#[DateFormat('Y-m-d')]
public DateTimeImmutable $birthday,

#[Caster(MoneyCaster::class)]
public Money $price,
```

`#[Cast]` and `#[Caster]` are mutually exclusive — both on the same property throws `ConfigurationException::castAndCasterConflict`.

See [Type Casting](/features/type-casting).

### `#[EmptyToNull]`

Read a blank string — `""` or only whitespace — as no value, after normalization and before validation. Like any field with [no value](/features/validation#no-value), it is checked by presence rules alone: a nullable property gets `null`, one with a default keeps it, any other reports `required`. Without it a string property keeps `""`, and "not filled in" ends up in two shapes (`''` and `NULL`) in one column:

```php
#[EmptyToNull]
#[Validate('date:Y-m-d')]
public ?string $endDate = null,     // "" → null

#[EmptyToNull]
#[Validate('in:created_at,name')]
public string $sort = 'created_at', // "" → 'created_at'
```

On a class it applies to every field of the class and its subclasses that the client sends — `#[FromContext]` and generated values are the application's and stay as they are:

```php
#[EmptyToNull]
#[Input]
final readonly class UpdateLeadInput { /* ... */ }
```

Only strings are blank: `[]` stays an array (and fails a `string` field's cast). Combining it with `#[Generator]` throws `ConfigurationException::generatorConflict`.

### `#[NoTrim]`

Auto-trim (on by default) skips the field: the string reaches normalizers, the validator and the constructor as sent. For values whose surrounding whitespace means something — code, Markdown, a fixed-width field:

```php
#[NoTrim]
#[Validate('required|string')]
public string $snippet,   // "  echo 1;\n" stays as it is
```

On a class it applies to every field of the class and its subclasses; `#[FromContext]` values are never auto-trimmed anyway. With `#[EmptyToNull]`, a whitespace-only string is still read as no value. Combining it with `#[Generator]` throws `ConfigurationException::generatorConflict`.

### `#[Normalize(class, config: [...])]` and `#[PostProcess(class, config: [...])]`

`Normalize` requires a `NormalizerInterface` class and prepares every present raw value before validation. `PostProcess` requires a `PostProcessorInterface` class and transforms non-null decoded values only after the whole tree is valid. It never disables casting. Both receive configuration, route and application context through `ProcessContext`.

```php
#[Normalize(SkuNormalizer::class)]
#[Validate('required|string')]
public string $sku,

#[PostProcess(ClampLimit::class)]
public int $limit = 20,
```

See [Processing stages](/features/processors) for complete handler implementations and error rules.

### `#[Generator(class, options: [...])]`

Generate the value instead of reading it from any source:

```php
#[Generator(UuidGenerator::class)]
public string $id,

#[Generator(SequenceGenerator::class, options: ['table' => 'orders'])]
public int $orderNumber,
```

`#[Generator]` is exclusive with `#[Validate]`, `#[Normalize]`, `#[PostProcess]`, `#[Cast]`, `#[Caster]`, `#[Items]`, and any source attribute — they would have nothing to act on and trigger `ConfigurationException::generatorConflict` at build time.

See [Generators](/features/generators).

### `#[Items(NestedInput::class)]`

The field is a list of nested input DTOs. Each element is processed through the items class:

```php
#[Validate('array|min:1')]
#[Items(OrderItemInput::class)]
public array $items,
```

`#[Items]` is exclusive with `#[Cast]` and `#[Caster]` (item processing replaces casting). A non-array value on an items field produces a structured `ValidationException` (`['field' => [['rule' => 'array']]]`), not a TypeError.

`$route` is **not** propagated into nested items; `$context` IS.

See [Nested Items](/features/nested-items).

### `#[Group('name', mapTo: '...')]`

Assign the field to a named group for `Inputs::group()` extraction:

```php
#[Group('filters')]
public ?string $search = null,

#[Group('criteria', mapTo: 'price >=')]
public ?string $min_price = null,
```

Null-valued group members are skipped from the output. `mapTo` is the output key — a column name or a criteria key with its operator.

See [Field Grouping](/features/grouping).

### `#[Exclude]`

The field goes through the input pipeline normally (validated, cast, processed) but is **omitted from `Inputs::toArray()`**. Useful for sensitive fields:

```php
#[Validate('required|string|min:8')]
#[Exclude]
public string $password,

// $input->password         // "secret123" — accessible in code
// Inputs::toArray($input)   // ['name' => 'X'] — no password leaks
```

---

## Fields without any attribute

Every constructor parameter is a field. Without attributes it is read from the default-source bag (Body for `fromBody`/`fromArray`, Query for `fromQuery`) by name, with a type-driven cast.

```php
#[Input]
final readonly class UserInput
{
    public function __construct(
        #[Validate('required|string')]
        public string $name,           // validated
        public ?string $email = null,  // read by name, no validation
        public int $age = 18,          // read by name + type-driven int cast
    ) {
    }
}
```

State that is not input stays out of the constructor: a property the constructor does not take is not a field, and a field attribute on it fails the build (`ConfigurationException::notAField`) instead of silently never running.

---

## Complete example

```php
use Solo\Input\Attributes\{Validate, DateFormat, Normalize, PostProcess, Generator, Items, Group, Exclude};
use Solo\Input\Attributes\Source\{FromRoute, FromContext};

#[Input]
final readonly class UpdateOrderInput
{
    /**
     * @param list<OrderItemInput> $items
     */
    public function __construct(
        #[FromRoute]
        #[Validate('required|integer|exists:orders,id')]
        public int $id,
        #[Generator(UuidGenerator::class)]
        public string $revisionId,
        #[Validate('required|integer|exists:users,id')]
        public int $customerId,
        #[FromContext('tenantId')]
        #[Validate('required|integer')]
        public int $tenantId,
        #[FromContext('authUserId')]
        #[Validate('required|integer')]
        public int $updatedBy,
        #[Validate('array|min:1')]
        #[Items(OrderItemInput::class)]
        public array $items,
        #[Validate('string|max:500')]
        // Client strings are auto-trimmed by default.
        public ?string $notes = null,
        #[Validate('in:pending,confirmed')]
        #[Exclude]
        public string $status = 'pending',
        #[DateFormat('Y-m-d')]
        #[Group('meta')]
        public ?DateTime $deliveryDate = null,
    ) {
    }
}
```
