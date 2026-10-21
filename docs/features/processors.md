# Normalizers, Casters and Post-processors

Each stage has one responsibility:

| Attribute | Interface method | Input | Result |
|---|---|---|---|
| `#[Normalize(Class::class, config: [])]` | `NormalizerInterface::normalize(mixed, ProcessContext)` | Present raw input, including `null` and `""` | Normalized input for validation |
| `#[Caster(Class::class, config: [])]` | `CasterInterface::cast(mixed, ProcessContext)` | Normalized input after raw validation and empty-value policy | The property's declared type |
| `#[PostProcess(Class::class, config: [])]` | `PostProcessorInterface::postProcess(mixed, ProcessContext)` | Non-null decoded value, after the whole input tree is valid | A value compatible with the same property |

Handlers are classes implementing the matching interface. Functions, method-name strings and using a caster as a normalizer/post-processor are not supported.

## Normalization

Normalizers run for every present field, even an explicit `null` or empty string. They accept `mixed`; there is no signature-based conversion or automatic skip. Leave unsupported input for validation or throw `FieldValidationException` for the current field.

```php
use Solo\Input\Attributes\{Normalize, Validate};
use Solo\Input\Contracts\NormalizerInterface;
use Solo\Input\ProcessContext;

final class SkuNormalizer implements NormalizerInterface
{
    public function normalize(mixed $value, ProcessContext $context): mixed
    {
        return is_string($value) ? strtoupper($value) : $value;
    }
}

// A field of an #[Input] class:
#[Normalize(SkuNormalizer::class)]
#[Validate('required|string')]
public string $sku,
```

The order is **auto-trim → custom normalizer → `#[EmptyToNull]` → validation**. A normalizer may therefore turn a present null into a value, or produce a blank that `#[EmptyToNull]` reads as null. `#[NoTrim]` disables only auto-trim. Context values are not auto-trimmed, but an explicitly attached normalizer runs on them too.

Normalizers prepare input; they do not make an otherwise unproducible class property valid configuration. Use a caster to construct a value object.

## Custom Casting

A custom caster replaces the built-in decoder for that field. It runs during input resolution, before post-processors and generators. It does not run if raw validation already rejected the field. It may run even when another field has errors, so it must be a pure conversion.

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

#[Caster(MoneyCaster::class)]
public Money $price,
```

`Money` and `InvalidMoney` in this example belong to the application. Conversion from other scalar types must be explicit in the caster.

`#[Cast]`, enum/date decoding and nested DTOs remain built-in alternatives. `#[Caster]` is mutually exclusive with `#[Cast]`, `#[DateFormat]` and `#[Items]`.

## Post-processing

Post-processors receive already decoded values. Adding one never disables casting. A numeric query string becomes an integer before the post-processor runs; invalid input never reaches it.

```php
use Solo\Input\Attributes\PostProcess;
use Solo\Input\Contracts\PostProcessorInterface;
use Solo\Input\ProcessContext;

/** @implements PostProcessorInterface<int> */
final class ClampLimit implements PostProcessorInterface
{
    public function postProcess(mixed $value, ProcessContext $context): int
    {
        return max(1, min(100, $value));
    }
}

#[PostProcess(ClampLimit::class)]
public int $limit = 20,

// "200" → int(200) → int(100)
// "abc" → ValidationException: integer; ClampLimit never runs
```

PHP method parameters remain `mixed`; `@implements PostProcessorInterface<int>` supplies the type to PHPStan. The factory checks compatibility with the declared field type before and after processing. Results are not passed through another cast: `true` or `"5"` cannot become an `int`, and `null` cannot reset a non-nullable field to its default. An incompatible result throws `UnexpectedValueException` naming the producer. Normal PHP widening from int to float remains compatible.

A post-processor transforms valid data. Input rejection belongs in normalization, validation or casting. Throwing either `FieldValidationException` or `ValidationException` during post-processing raises `LogicException` with the original exception attached. Computational work such as hashing is appropriate; external writes belong in application code after successful handling.

## Errors

A normalizer or caster rejects its own field with:

```php
throw new FieldValidationException('money', params: ['decimal']);
```

The factory attaches the actual input path and collects errors from the whole tree:

```php
// The same caster can serve price, amount, or a nested field:
['lines.2.price' => [['rule' => 'money', 'params' => ['decimal']]]]
```

The public aggregate is still `ValidationException`. Do not throw that aggregate directly from a normalizer/caster: it is a contract error (`LogicException`). Cross-field checks belong in the validator. A normalizer failure omits that field from the normalized validation bag; the remaining fields are still checked. A field with a raw validation error does not run its caster.

A field rejection on `#[FromContext]` becomes `UnexpectedValueException`: the application supplied that value. An incorrect caster or post-processor result is also an application error, regardless of the input source. Exceptions other than these explicit validation exceptions propagate normally.

## Missing, Null and Blank Values

| Situation | Behavior |
|---|---|
| Key absent | No normalizer, caster or post-processor; the existing required/default rules apply |
| Key present as null or empty string | Normalizer runs |
| Null after normalization | No caster; nullable property gets null, otherwise use its default or report `required` |
| Null after decoding | No post-processor |
| Non-null default selected for a present field | Post-processor receives the default |
| Default left untouched because a key was absent | No post-processor; `provided()` does not include it |
| Caster/post-processor returns null | Accepted only by a nullable property; no default fallback |

Without `#[EmptyToNull]`, empty strings follow the property's [empty-value policy](/features/type-casting#null-and-empty-string-handling). For a custom caster, `""` is passed through for a string/mixed-compatible property, becomes an empty array for an array/iterable property, and otherwise means no value. Casters are bypassed when this policy selects no value or an empty array. The final value is still type-checked. Context `""` remains an actual value rather than client absence.

## Nested DTOs and Finalization

Input resolution normalizes, validates and decodes every node, collecting errors. Only after the entire tree succeeds does finalization start, from children to parents:

1. Finalize children into complete DTOs.
2. Run this node's post-processors on non-null decoded values.
3. Run this node's generators.
4. Populate this node's DTO.

A nested post-processor receives a DTO, and an `#[Items]` post-processor receives an array of DTOs, including their generated fields. Returned values must still satisfy the property type and item class. They are not revalidated or re-decoded. A child generator can run before a parent's post-processor. Generators cannot reject input with validation exceptions either; these are `LogicException` contract errors during finalization. Finalization does not provide rollback for application failures.

List members are checked again when assigned to the DTO: a later handler may
have changed an element through a PHP reference. This final check preserves the
declared item class without rerunning the child's pipeline.

To decode a JSON string into raw items, use a normalizer before the standard child pipeline:

```php
use Solo\Input\Attributes\Items;

final class JsonArrayNormalizer implements NormalizerInterface
{
    public function normalize(mixed $value, ProcessContext $context): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }
        try {
            return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new FieldValidationException('json', previous: $e);
        }
    }
}

#[Normalize(JsonArrayNormalizer::class)]
#[Validate('array')]
#[Items(OrderItemInput::class)]
public array $items,
```

## Configuration and Dependencies

All three attributes accept `config: [...]`. Their `ProcessContext` exposes `config`, `route` and `context`; child DTOs receive the outer context but not the outer route. The DTO is not passed into field handlers, so there is no field-order dependency on partially constructed siblings.

Instances resolve through `InputFactory::register()`, the PSR-11 container, or a no-argument constructor. Registered and automatically created instances are reused by the factory; container entries are fetched on each use so the container controls their scope. Keep handlers free of per-request mutable state. See [Configuration](/guide/configuration#dependency-injection).
