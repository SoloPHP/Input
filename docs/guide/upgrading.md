# Upgrading

## From 1.0 to 1.1

1.1 settles what an empty input means: `null`, and a `""` the field's type cannot hold, are no value. The factory reads such a `""` as `null` before validation, the validator checks a `null` like a missing field — by presence rules alone — and the field then holds `null`, keeps its default, or reports `required`. That needs [solophp/validator](https://github.com/SoloPHP/Validator) 4.2, which reads `null` this way, and solophp/contracts 1.8, which makes it part of `ValidatorInterface`. Attributes, `InputFactory`, `Inputs` and the schema format stay as they were.

### No value is checked by presence rules alone

A field has [no value](/features/validation#no-value) when it is `null` — sent as null, returned by a normalizer, or a blank string `#[EmptyToNull]` read as null — or a `""` its type cannot hold, such as `?page=` for an `int`. 1.0 checked such a field with every rule, unless its rules said `nullable`. 1.1 checks it with its presence rules alone — `required`, `filled`, `present`, `required_if` and the other `required_*` rules.

| Field | Sent | 1.0 | 1.1 |
|-------|------|-----|-----|
| `#[Validate('string')] public ?string $note = null` | `null` | `string` error | `null` |
| `#[Validate('boolean')] public ?bool $done = null` | `?done=` | `boolean` error | `null` |
| `#[EmptyToNull] #[Validate('string\|max:100')] public ?string $q = null` | `?q=` | `string` error | `null` |
| `#[Validate('integer\|min_value:1')] public int $page = 1` | `?page=` | `integer` error | `1` |
| `#[EmptyToNull] #[Validate('string')] public string $name` | `""` | `string` error | `required` error |
| `#[Validate('nullable\|string')] public ?string $note = null` | `null` | `null` | `null` |

Error codes follow: a field with no value fails with a presence rule's code (`required`, `filled`, `required_if`…) or `required`, not with the first rule it failed in 1.0. A value — a filled string, `""` for a `string` property, `[]` — is checked by every rule, as in 1.0, and so is a field the client did not send.

Rules that read another field see it the same way: a `""` its type cannot hold is `null` there too. `required_if:count,null` now applies when `count`, an `int`, is sent as `""`, and a `{count}` placeholder in a rule of your own resolves to `null`, not `""`.

### A `""` is no list

1.0 read a `""` for an `array` or `#[Items]` field as `[]` — and still let `array` refuse it — and gave a custom caster's array type `[]` without calling the caster. 1.1 reads it as no value, like any `""` a type cannot hold. Only a `[]` the client sends is the empty list, which every rule checks:

| Field | Sent | 1.0 | 1.1 |
|-------|------|-----|-----|
| `#[Validate('array')] public ?array $tags = null` | `""` | `array` error | `null` |
| `#[Validate('array\|min:1')] #[Items(LineInput::class)] public array $lines = []` | `""` | `array` error | `[]`, the default |
| `#[Validate('nullable\|array')] public ?array $tags = null` | `""` | `[]` | `null` |
| `#[Validate('in:all,unread')] #[Caster(StatusCaster::class)] public ?array $status = null` | `""` | `in` error | `null` |
| `#[Validate('array\|min:1')] public array $lines = []` | `[]` | `min` error | `min` error |

### Refuse no value with `filled`

In 1.0 a nullable field without `nullable` in its rules refused `null`, so in a partial update it could be left out but not cleared. In 1.1 a `null` clears it. Say that it must not be cleared with `filled`, which lets the field be absent but not blank:

```php
// 1.0
#[Validate('string|max:255')]
public ?string $name = null,

// 1.1
#[Validate('filled|string|max:255')]
public ?string $name = null,
```

A non-nullable field with a default now takes its default for no value — in a partial update a `null` resets it to the default. `filled` refuses that too, and `required` refuses no value on a field without a default.

The fields to review have a nullable type or a default, and rules with neither `nullable` nor `required` or `filled`. That includes fields with conditional rules such as `required_if`: they let no value through whenever their condition does not hold.

### Rules of your own

solophp/validator runs a rule you register on a missing or `null` field only when it implements `PresenceRule`; a callback rule never does. `#[Validate('accepted')] public bool $terms = false` now takes `false` for a `null` or `""` — as it already did in 1.0 for a missing key. Drop the default and write `required|accepted`.

### `#[FromContext]` values

A `null` in the context is no value too: a nullable field gets `null` and one with a default keeps it, where 1.0 reported the field's first failing rule. One with neither throws `UnexpectedValueException`, as a missing context key does.

### `nullable` is optional

The type says whether a field may be null, so `nullable` is no longer needed. It keeps working and is passed on as written. With solophp/validator it also lets a blank `""` or `[]` past the other rules — the one thing it still adds. Where `""` should mean no value, use [`#[EmptyToNull]`](/features/field-attribute#emptytonull) instead.

### A validator of your own

Since solophp/contracts 1.8, `ValidatorInterface` checks a missing or `null` field by presence rules alone. A validator of your own has to keep that: one that checks `null` with every rule fails a field with no value on its first rule, as in 1.0. See [Validator Implementation](/features/validation#validator-implementation).
