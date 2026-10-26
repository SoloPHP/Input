# Type Casting

The library automatically casts input values based on property types, with optional `#[Cast]` and `#[Caster]` attributes for explicit control.

## Automatic Casting (driven by declared type)

When no `#[Cast]` or `#[Caster]` is specified, values are cast based on the property type:

```php
public int $count,                // Cast to int
public float $price,              // Cast to float
public bool $active,              // Cast to bool
public string $name,              // Cast to string
public array $tags,               // Cast to array
public DateTimeImmutable $at,     // Parsed into DateTimeImmutable (also for DateTimeInterface)
public DateTime $legacyAt,        // Parsed into DateTime
public Status $status,            // BackedEnum — resolved by backing value
public AddressInput $address,   // an #[Input] class — a nested DTO (see Nested Objects)
```

An `iterable` property keeps an array or a Traversable as it is — a generator is not consumed — and reads anything else like `array`. A property of any other class type — and one typed `object`, with an intersection, or with a union of classes only — needs something that produces it: `#[Caster]`, a `#[Generator]` or `#[FromContext]`; otherwise building the metadata throws `ConfigurationException::unproducibleType`.

### Union types

A union with a scalar or `array` member and no `#[Cast]` keeps a value of one of its types and converts any other value to the first scalar member it fits without loss — `int`, then `float`, `string`, `bool`, PHP's own order:

```php
public int|float $amount,   // 5 → 5, "5" → 5, "5.5" → 5.5, "abc" → numeric error
public int|string $code,    // "007" → "007" (a string already fits), 2.0 → 2
public array|string $tags,  // ['a'] → ['a'], 7 → "7"
```

A value that fits no member is a validation error named after the widest member: `string`, then `numeric`, `integer`, `boolean`, `array`. `""` for a union without `string` means no value, as for a number.

The literal types `true` and `false` — alone or in a union — are reached through the bool cast, then kept only when the result is the literal:

```php
public true $acceptTerms,         // true, "1", "yes", "on" → true; "0", false → boolean error
public string|false $nickname,    // false → false, "x" → "x"
```

Casts are **lossless**: a value is converted only when nothing is lost on the way — `"42"` becomes `42`, but `"42abc"` does not become `42`. Everything else is a validation error.

## Cast Failures Are Validation Errors

A value that cannot become the declared type is reported in the `ValidationException`, together with the validator's errors, instead of being coerced into something the client did not send:

| Input | Error |
|-------|-------|
| Not an integer for an `int` property (`"12abc"`, `"1.5"`, `[5]`) | `['rule' => 'integer']` |
| Not a number for a `float` property (`"9,99"`, `[1.5]`) | `['rule' => 'numeric']` |
| Not a number for `#[Cast('decimal')]` (`"abc"`, `"1e400"`) | `['rule' => 'numeric']` |
| Not a boolean form for a `bool` property (`"maybe"`, `2`) | `['rule' => 'boolean']` |
| An array, object or boolean for a `string` property | `['rule' => 'string']` |
| Unparseable date, no format | `['rule' => 'date']` |
| Unparseable date for `#[DateFormat('<format>')]` | `['rule' => 'date_format', 'params' => ['<format>']]` |
| Value matching no enum case | `['rule' => 'in', 'params' => [...backing values]]` |
| `""` or `null` for a non-nullable property without a default | `['rule' => 'required']` |

The `int`, `float` and `bool` casts accept the forms the `integer`, `numeric` and `boolean` rules of [solophp/validator](https://github.com/solophp/validator) accept — except that a JSON `true` / `false` is never a number — and report the same error codes. So the type alone already rejects `"12abc"`; a matching rule in `#[Validate]` stays harmless.

An empty string keeps its meaning "no value": it becomes `null` for `int`, `float`, `bool`, DateTime and enum properties, and under `#[Cast('decimal')]` — `null` on a nullable property, the default on one that has a default, and the `required` error otherwise. A field that already failed validation is not cast, so it reports the validator's error only.

An array for a `string` property is a `string` error — including a `#[FromBody]` dot-path that stops at a sub-object. To store structured input in a string property (a JSON column, say), declare [`#[Cast('json')]`](#json). A normalizer can prepare the raw value, or a custom caster can explicitly convert it; a post-processor receives the decoded value.

Values the application supplies — `#[FromContext]` and generators — are not client input. They go through the same casts, but one that does not fit the declared type throws `UnexpectedValueException` instead of producing a validation error: `1.5` or `true` for an `int`, `2` for a `bool`, `true` for a `string`, an array or another class for a class-typed property. `""` is a value the application passed, not the absence of one: for a type that cannot hold a string — a number, a date, an object — it does not fit either. A `null` — or a missing context key — for a field that is neither nullable nor has a default throws too. Context values are resolved before finalization. Custom casters run during resolution too; their results and post-processor results are checked strictly. See [Post-processing after casting](#post-processing-after-casting).

## Explicit Casting (`#[Cast]`)

Use `#[Cast]` when the declared type does not say what to produce — a union or `mixed` property — to JSON-encode into a string, or to write a number into one in plain decimal notation. It accepts `int`, `float`, `bool`, `string`, `array`, `json` and `decimal` (the 3.x aliases `integer`, `double`, `boolean` are gone); dates are cast by their type, see [Dates](#dates):

```php
use Solo\Input\Attributes\Cast;

#[Cast('float')]
public int|float $amount,

#[Cast('array')]
public mixed $payload = null,

#[Cast('json')]
public ?string $settings = null,   // ['theme' => 'dark'] → '{"theme":"dark"}'

#[Cast('decimal')]
public string $rate = '1',         // 0.00001 → '0.00001'
```

---

## Built-in Type Casting

### Integer

| Input | Result |
|-------|--------|
| `123`, `"123"`, `"-5"`, `"+7"` | `123`, `123`, `-5`, `7` |
| A whole float (`2.0`) | `2` |
| `"12abc"`, `"1.5"`, `"1e3"`, `"05"`, `2.5`, `true`, an array | `integer` error |

Strings follow `FILTER_VALIDATE_INT`: digits with an optional sign — no fraction, exponent or leading zero.

### Float

| Input | Result |
|-------|--------|
| `12.34`, `"12.34"`, `"1e3"` | `12.34`, `12.34`, `1000.0` |
| `5` | `5.0` |
| `"9,99"`, `"abc"`, `true`, an array, `"1e400"` (overflows to `INF`) | `numeric` error |

### Boolean

| Input | Result |
|-------|--------|
| `true`, `1`, `"true"`, `"1"`, `"yes"`, `"on"` (any case) | `true` |
| `false`, `0`, `"false"`, `"0"`, `"no"`, `"off"` (any case) | `false` |
| `""` | no value — `null`, the default, or `required` |
| Anything else (`"maybe"`, `2`, `1.0`) | `boolean` error |

### String

| Input | Result |
|-------|--------|
| `"abc"` | `"abc"` |
| `123`, `1.5` | `"123"`, `"1.5"` |
| A `Stringable` object | its string |
| An array, `true` / `false`, any other object | `string` error |

A float is written the way PHP writes it: rounded to the 14 significant digits of the `precision` setting, and in exponent notation when very small or large — `0.1 + 0.2` as `"0.3"`, `0.00001` as `"1.0E-5"`. For a number meant for a DECIMAL column or bcmath, use [`#[Cast('decimal')]`](#decimal).

### JSON

Only by `#[Cast('json')]`, on a `string` (or untyped / `mixed`) property:

| Input | Result |
|-------|--------|
| `['theme' => 'dark']` | `'{"theme":"dark"}'` |
| `'{"theme":"dark"}'` (a string) | kept as it is |
| `true`, `5` | `'true'`, `'5'` |

A string is not checked to be valid JSON — add a validation rule of your own if it must be.

### Decimal

Only by `#[Cast('decimal')]`, on a `string` (or untyped / `mixed`) property. It writes a number in plain decimal notation — what a DECIMAL column, bcmath or a decimal library takes:

```php
#[Validate('required|numeric|min_value:0')]
#[Cast('decimal')]
public string $rate,   // 0.00001 → '0.00001', '1e-5' → '0.00001'
```

| Input | Result |
|-------|--------|
| `5`, `"+5"`, `"005"`, `"5.0"` | `"5"` |
| `0.00001`, `"1e-5"`, `"1.0E-5"` | `"0.00001"` |
| `41.25`, `"41.250"` | `"41.25"` |
| `"12345678901234567890.123456789"` | kept digit for digit |
| `-0.0`, `"-0.000"` | `"0"` |
| `""` | no value — `null`, the default, or `required` |
| `"abc"`, `"1,5"`, `true`, an array, `"1e400"`, `"1e-400"` | `numeric` error |

The notation changes, never the value. A string keeps every digit it has. A float gives its shortest round-trip digits, so `0.1` stays `"0.1"`. Precision and scale remain the column's to enforce: they differ by column and by database, so `"1e308"` passes as its 309 digits. The range is a float's: a number a float would overflow to infinity, or round to zero although it is not zero, is a `numeric` error. That bounds how far the point moves, so a short input cannot expand without limit — `"1e-99999999"` would be a hundred million characters.

### Array

Smart conversion logic:

1. **JSON**: Valid JSON array/object is decoded
2. **CSV**: Comma-separated string is split
3. **Single**: Non-empty string wrapped in array
4. **Empty**: Empty string becomes `[]`
5. **Traversable** (a value from context, say a generator): its items

| Input | Result |
|-------|--------|
| `'["a","b"]'` | `["a", "b"]` |
| `'a, b, c'` | `["a", "b", "c"]` |
| `'single'` | `["single"]` |
| `''` | `[]` |

---

## Dates

A property typed `DateTimeImmutable`, `DateTime` or `DateTimeInterface` is parsed from the input — no attribute needed. The class comes from the type; `DateTimeInterface` gets `DateTimeImmutable`:

```php
public DateTimeImmutable $createdAt,       // "2024-01-15", "2024-01-15T10:30:00+02:00", ...
public DateTime $legacyAt,                 // a mutable DateTime
public ?DateTimeInterface $seenAt = null,  // a DateTimeImmutable

// Also accepted: Unix timestamps (1705276800 or "1705276800") and DateTime
// objects passed via context — converted to the declared class.
```

A string of digits is a date when it spells one (`"20240115"`) and a Unix timestamp when PHP's parser cannot read it (`"1705276800"`). Digits the parser would take for a time of day — `"2024"` is 20:24 today — are a `date` error.

An impossible date or time — `2024-02-31`, `23:61` — is a `date` error (`date_format` with `#[DateFormat]`), not the 2 March or the next hour PHP's parser would roll it over into.

### Fixed format — `#[DateFormat]`

```php
use Solo\Input\Attributes\DateFormat;

#[DateFormat('Y-m-d')]
public DateTimeImmutable $birthDate,   // "1990-05-01" → 1990-05-01 00:00:00

#[DateFormat('Y-m-d H:i:s')]
public DateTime $eventTime,            // formats may contain ':'
```

A number is read in the format like a string — `20240115` for `#[DateFormat('Ymd')]`; declare `U` to accept a Unix timestamp. A format without time-of-day fields yields midnight. Other fields the format does not parse keep `createFromFormat()` semantics and come from the current date — `m-d` is in the current year; add `!` or `|` to the format to reset them yourself (`!Y-m` is the first of the month).

`#[DateFormat]` on an untyped or `mixed` property produces a `DateTimeImmutable`; on any other non-date type it is a `ConfigurationException`. It cannot be combined with `#[Cast]`, `#[Caster]` or `#[Items]`.

`#[Cast]` has no date types: the class follows the field type, and `#[DateFormat]` fixes the format. `#[Cast('datetime')]` is an unknown cast type and fails the build.

---

## Enums

A property typed with a `BackedEnum` is resolved through its backing value — no attribute needed. An int-backed enum reads the value like an `int` property: integer strings too, since query strings carry no ints, and whole floats such as `2.0`:

```php
enum Status: string { case Draft = 'draft'; case Published = 'published'; }
enum Priority: int { case Low = 1; case High = 2; }

public Status $status,                        // "published" → Status::Published
public Priority $priority = Priority::Low,    // "2"         → Priority::High
public ?Status $filter = null,                // ""          → null

// "archived" → ['status' => [['rule' => 'in', 'params' => ['draft', 'published']]]]
```

Enum instances passed through `context` are kept as they are. Pure (non-backed) enums still need a `#[Caster]`.

---

## Custom Casters (`#[Caster]`)

Implement `CasterInterface` for complex types and plug them in via `#[Caster]`:

```php
use Solo\Input\Attributes\Caster;
use Solo\Input\Contracts\CasterInterface;
use Solo\Input\Exceptions\FieldValidationException;
use Solo\Input\ProcessContext;

/** @implements CasterInterface<Money> */
final class MoneyCaster implements CasterInterface
{
    public function cast(mixed $value, ProcessContext $context): Money
    {
        if (!is_string($value)) {
            throw new FieldValidationException('money');
        }
        try {
            return Money::fromDecimal($value);
        } catch (InvalidMoney $e) {
            throw new FieldValidationException('money', previous: $e);
        }
    }
}
```

`Money` and `InvalidMoney` are application classes. Casters run during resolution, even if another field is invalid; keep conversion pure. Configuration is available through `$context->config`.

Usage:

```php
#[Caster(MoneyCaster::class)]
public Money $price,
```

`#[Cast]` and `#[Caster]` are mutually exclusive — declaring both on the same property throws `ConfigurationException`.

### Caster with Dependencies

A caster with constructor dependencies comes from the PSR-11 container passed to `InputFactory`, or from a registered instance:

```php
/** @implements CasterInterface<Money> */
final class CurrencyCaster implements CasterInterface
{
    public function __construct(
        private readonly CurrencyConverter $converter
    ) {}

    public function cast(mixed $value, ProcessContext $context): Money
    {
        return $this->converter->convert($value);
    }
}

// Register
$factory->register(CurrencyCaster::class, new CurrencyCaster($converter));
```

---

## Post-processing After Casting

`#[PostProcess]` receives a non-null value after built-in/custom casting and after the whole input tree is valid. It never bypasses casting or creates raw nested data. Results must fit the declared type without scalar coercion (ordinary int-to-float widening is compatible). A wrong result, including null for a non-nullable property with a default, throws `UnexpectedValueException`.

Use `#[Normalize(JsonArrayNormalizer::class)]` before `#[Items]` when the raw input needs JSON decoding. Use `#[Caster]` when input must become a value object. Post-processors cannot report input-validation failures. See [Processors](/features/processors) for interfaces and examples.

---

## Null and Empty String Handling

`null` remaining after normalization is never cast: a nullable property gets `null`, one with a default keeps its default, and any other property reports `required`.

Empty string `""` is handled per type:

| Type | `""` Result | Reason |
|------|-------------|--------|
| `int`, `float`, `bool` | no value | Not a number / boolean |
| `#[Cast('decimal')]` | no value | Not a number |
| date types, enums | no value — the case, for an enum with a `""` case | Not a date / case |
| a custom-cast class | no value | No class holds a string |
| `string` | `""` | Preserved as-is |
| `array`, an `#[Items]` list | `[]` | Empty collection |
| a nested DTO | no value | No object |

"No value" means `null` on a nullable property, the default on one that has a default, and the `required` error otherwise. [`#[EmptyToNull]`](/features/field-attribute#emptytonull) extends that to `string` and `array` properties — on one property or a whole class.

---

## Type Safety

Configuration is validated at build time:

```php
// ❌ Error: cast type incompatible with property type
#[Cast('string')]
public int $id,

// ✅ Valid: types match
#[Cast('int')]
public int $id,

// ✅ Valid: float is compatible with int|float
#[Cast('float')]
public int|float $price,
```
