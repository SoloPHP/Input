# Validation

Validation rules are declared via the `#[Validate]` attribute.

## Basic Rules

```php
use Solo\Input\Attributes\Validate;

#[Validate('required|string|max:255')]
public string $name,

#[Validate('required|email')]
public string $email,

#[Validate('required|integer|min_value:0')]
public int $quantity,
```

## Required vs Optional

A field's default decides whether it may be absent: the constructor needs a value for every parameter without one. Such a field that the input lacks fails `required`, with or without a rule saying so — unless the validator already reported it.

```php
// Required: must be sent, with a value
#[Validate('required|string')]
public string $username,

// Required by the constructor: absent fails `required`, but null is accepted
#[Validate('string')]
public ?string $reason,

// Optional: absent or null, it is null
#[Validate('string')]
public ?string $bio = null,

// Optional: absent or with no value, it is 1
#[Validate('integer|min_value:1')]
public int $page = 1,
```

PHP expects required parameters before optional ones: declare the fields without defaults first.

## No Value

`null` and `""` are no value — except in a field that holds text, where `""` is the empty text unless [`#[EmptyToNull]`](/features/field-attribute#emptytonull) reads it as no value too. Everything else, `[]` included, is a value.

After normalization the factory reads a `""` the field's type cannot hold as `null`: for `int`, `float`, `bool`, a union without `string`, `#[Cast('decimal')]`, a date, an enum without a `""` case, an `array` or `#[Items]` list, a nested DTO, and any type a custom caster builds that cannot hold a string — a class, an array. The validator checks a `null` like a missing field, by its [presence rules](https://solophp.github.io/Validator/features/rules#presence-rules) alone — `required`, `filled`, `present`, `required_if` and the other `required_*` rules. The field then holds `null` when nullable, its default when it has one, and the `required` error otherwise.

```php
// GET /tasks?q=&done=  →  $q and $done are null
#[EmptyToNull]
#[Validate('string|max:100')]
public ?string $q = null,

#[Validate('boolean')]
public ?bool $done = null,
```

The type says whether a field may be null, so it needs no `nullable` rule. A value goes through every rule: a filled string, `""` for a `string` property, and `[]` — the empty list, which `min:1` refuses. A `""` is no list: `array $tags = []` keeps its default, `?array $tags` is `null`. A custom caster never sees a `""` its type cannot hold.

A field the client may leave out but not clear refuses no value with `filled`, one without a default with `required`:

```php
#[Input(partial: true)]
final readonly class UpdateProductInput
{
    public function __construct(
        #[Validate('filled|string|max:255')]
        public ?string $name = null,   // may be left out; null or "" fails `filled`
        #[Validate('string|max:1000')]
        public ?string $note = null,   // null clears it
    ) {
    }
}
```

A rule of your own runs on a field with no value only if it is a presence rule: solophp/validator calls it for a missing or `null` field only when it implements `PresenceRule`, and never a callback. So `#[Validate('accepted')] public bool $terms = false` takes `false` for `null` or `""`, as it does for a missing key: drop the default and write `required|accepted`.

A `null` the application passes in `#[FromContext]` is no value too: a nullable field gets `null`, one with a default keeps it, and one with neither throws `UnexpectedValueException`, as a missing context key does.

A `nullable` you write keeps working. With solophp/validator it also lets a blank `""` or `[]` past the other rules: where `""` means no value, say so with `#[EmptyToNull]`.

## Absent vs Null vs Empty String

The factory distinguishes these states for each field:

| What frontend sends | Meaning | Behavior |
|---------------------|---------|----------|
| Field absent | Don't touch | The default; without one, `required`. The validator's presence rules can reject it too |
| `"name": null` | No value | Presence rules alone; then `null` for nullable types, the default for others, or `required` without one |
| `"name": ""` | Empty string | A value for a field that holds text — kept and checked by every rule; no value for any other type, and under `#[EmptyToNull]` |
| `"name": "hello"` | Set value | Normal processing pipeline |

This distinction is important for PATCH-style updates: absent fields are not modified, `null` clears a nullable field unless `filled` refuses it, and `""` sets an empty string.

### Presence rules decide about absent fields

The factory passes an absent field's rules to the validator — but no value: the field stays absent from the validated data, as the client sent it, and the validator decides whether it may be missing. solophp/validator checks an absent field by its presence rules alone and does not apply `string` or `max:255` to a value nobody sent.

```php
#[Validate('in:individual,company')]
public string $company_type = 'individual',

// May be omitted in general — but not when company_type is 'company'.
#[Validate('string|max:255|required_if:company_type,company')]
public ?string $name = null,

// Must be sent, though an empty list is an answer: [] clears the tags,
// a missing key is refused — by `present`, and by the missing default.
#[Validate('present|array')]
public array $tags,
```

Note that unlike literal `required`, a `required_*` rule combined with a default value is legal — it is the intended usage pattern: a conditionally required field is optional to the constructor and needs its default.

---

## Common Rules

::: info Validator Implementation
Available rules depend on your `ValidatorInterface` implementation. The examples below assume [solophp/validator](https://github.com/SoloPHP/Validator) 4.1.
:::

| Rule | Description | Example |
|------|-------------|---------|
| `required` | Must be present and not empty | `'required'` |
| `filled` | May be absent; when present, not empty | `'filled'` |
| `present` | Must be present, may be empty | `'present'` |
| `nullable` | `""` and `[]` count as no value too, as `null` always does — not needed for [no value](#no-value) | `'nullable'` |
| `string` | Must be a string | `'string'` |
| `integer` | Must be an integer | `'integer'` |
| `numeric` | Must be numeric | `'numeric'` |
| `email` | Must be valid email | `'email'` |
| `boolean` | Must be boolean-like | `'boolean'` |
| `array` | Must be an array | `'array'` |
| `date` | Must be valid date (optionally in a format) | `'date'`, `'date:Y-m-d'` |
| `min:n` | Minimum length | `'min:3'` |
| `max:n` | Maximum length | `'max:255'` |
| `min_value:n` | Minimum numeric value | `'min_value:0'` |
| `max_value:n` | Maximum numeric value | `'max_value:100'` |
| `in:a,b,c` | Must be in list | `'in:active,inactive'` |
| `length:n` | Exact length | `'length:10'` |

::: warning Unknown rules throw
solophp/validator throws `InvalidRuleException` on a rule it does not know. Rules such as `unique`, `exists`, `same`, `accepted`, `after`, `url`, `alpha_num` or `size` in these docs are application rules — register them in the `Validator` constructor ([custom rules](https://solophp.github.io/Validator/features/custom-rules)):

```php
$validator = new Validator([
    'exists' => new ExistsRule($connection),
    'url' => fn (mixed $value): bool => filter_var($value, FILTER_VALIDATE_URL) !== false,
]);
```
:::

---

## Cross-field references

The factory passes rule strings to `ValidatorInterface::validate()` verbatim — it does not substitute placeholders or otherwise transform them.

Cross-field references in rules (`unique:users,email,{id}`, `exists:…,scope_col,{field}`, `required_if:type,manual`) are the validator's concern. A typical implementation resolves `{id}` / sibling-field references against the validation payload, which contains normalized fields present in the request (before casting, with a `""` the field cannot hold as `null`), except fields rejected by a normalizer — including those declared with `#[FromRoute]` and `#[FromContext]`, and those without a `#[Validate]` of their own.

So declare the referenced field as a real property:

```php
use Solo\Input\Attributes\Source\{FromRoute, FromContext};

#[Input]
class UpdateUserInput
{
    public function __construct(
        #[FromRoute]
        #[Validate('required|integer|exists:users,id')]
        public int $id,
        #[FromContext('tenantId')]
        public int $tenantId,
        #[Validate('required|email|unique:users,email,{id},tenant_id,{tenantId}')]
        public string $email,
    ) {
    }
}

$dto = $factory->fromBody(
    UpdateUserInput::class,
    $request,
    route:   ['id' => 123],
    context: ['tenantId' => 99],
);
// Validator sees data: ['id' => 123, 'tenantId' => 99, 'email' => '...']
// and resolves {id} / {tenantId} against that payload.
```

If your validator (or custom rule) needs a different convention, that's a validator-side concern — see your validator's docs. See [Route + context arguments](/guide/configuration#route-context-arguments) for how `$route` / `$context` are passed in.

---

## Handling Validation Errors

All errors of a request come back in one `ValidationException`: the validator's errors, normalizer rejections, cast failures (see [Type Casting](/features/type-casting#cast-failures-are-validation-errors)) and the errors of every nested object and item, in declaration order. Catch it to handle them.

### Error keys follow the input

Errors are keyed by where the client put the value, so a form can map them to its fields without knowing the PHP property names:

| Property | Error key |
|----------|-----------|
| `public string $name` | `name` |
| `#[FromBody('display_name')] public string $name` | `display_name` |
| `#[FromBody('customer.id')] public int $customerId` | `customer.id` |
| `#[FromRoute('product_id')] public int $productId` | `product_id` |
| `#[FromContext('authUserId')] public int $createdBy` | `authUserId` |
| a field of `#[FromBody('order.lines')] #[Items(...)]` | `order.lines.0.quantity` |

Rule strings still reference **field names** (`same:password`, `required_if:company_type,company`, `{id}`): the validator works on the DTO's fields. Normalizers and casters reject their own field with `FieldValidationException`; the factory supplies its input path. Throwing the aggregate `ValidationException` directly from a field handler is a contract error. See [Processing errors](/features/processors#errors).

```php
use Solo\Input\Exceptions\ValidationException;

try {
    $dto = $factory->fromBody(CreateUserInput::class, $request);
} catch (ValidationException $e) {
    $errors = $e->getErrors();
    // [
    //     'email' => [['rule' => 'email']],
    //     'password' => [['rule' => 'min', 'params' => ['8']]],
    // ]

    return $this->json(['errors' => $errors], 422);
}
```

### Error order and overlapping paths

- Fields are reported in DTO declaration order, even if the validator returns
  them in a different order.
- A nested object's or item's errors appear at its parent property's position.
  Items follow input iteration order and retain their original keys in paths.
- When several properties resolve to the same input path, their message lists
  are appended in that order. This also applies to a flat
  `#[FromBody('address.zip')]` field alongside a nested `address` DTO.
  The same applies when different item keys and child paths produce an identical
  path, including a non-object item's `array` error. Repeated messages are preserved.
- Additional validator keys that do not match DTO properties follow the
  property errors in their original order. If a key already exists in the
  output, its messages are appended there. Inside a child DTO, these keys also
  receive the child's path prefix.

Every message retains its `rule` and optional `params`. A validator returning
an error array and one throwing `ValidationException` use the same mapping
and ordering rules.

---

## Configuration Validation

Invalid configurations are detected when the DTO metadata is first built,
before input validation. This includes nested DTOs and `#[Items]` classes,
even when their input is absent, `null` or an empty list. The first
configuration error stops the build: properties are checked in metadata order,
and a child's configuration is checked where its parent property is encountered,
before moving on to later parent properties.

```php
// ❌ ConfigurationException: nullable rule with non-nullable type
#[Validate('nullable|email')]
public string $email,  // Should be ?string, which needs no 'nullable'

// ❌ ConfigurationException: required with default value
#[Validate('required|string')]
public string $name = 'default',  // Remove default or 'required'
```

---

## Validator Implementation

The package requires a validator implementing `ValidatorInterface`:

```php
interface ValidatorInterface
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $rules
     * @return array<string, list<array{rule: string, params?: string[]}>>
     */
    public function validate(
        array $data,
        array $rules
    ): array;
}
```

We recommend [solophp/validator](https://github.com/SoloPHP/Validator).

`validate()` runs once per input object and gets the rules of every field the client sends. An absent field's key is missing from the data, and a field with [no value](#no-value) is `null` in it. Since solophp/contracts 1.8 the interface says how both are checked: by presence rules alone. A validator of your own has to keep that; solophp/validator does from 4.2.

---

## Practical Examples

### User Registration

```php
#[Input]
final class RegisterInput
{
    public function __construct(
        #[Validate('required|string|min:3|max:50|alpha_num')]
        public string $username,
        #[Validate('required|email|unique:users,email')]
        public string $email,
        #[Validate('required|string|min:8')]
        #[Exclude]                                       // hide from toArray()
        public string $password,
        #[Validate('required|same:password')]
        #[Exclude]
        public string $passwordConfirmation,
        #[Validate('required|accepted')]
        public bool $termsAccepted,
    ) {
    }
}
```

### Product Creation

```php
#[Input]
final class CreateProductInput
{
    public function __construct(
        #[Validate('required|string|max:255')]
        public string $name,
        #[Validate('required|numeric|min_value:0')]
        public float $price,
        #[Validate('required|integer|exists:categories,id')]
        public int $categoryId,
        #[Validate('string|max:1000')]
        public ?string $description = null,
        #[Validate('array')]
        public ?array $tags = null,
        #[Validate('url')]
        #[EmptyToNull]                                   // "" is no image, not a bad URL
        public ?string $imageUrl = null,
        #[Validate('in:draft,published')]
        public string $status = 'draft',
    ) {
    }
}
```

### Search Filters

A search form sends its empty fields as `?query=&sortBy=`: on the class, `#[EmptyToNull]` reads each as no filter.

```php
#[EmptyToNull]
#[Input]
final class SearchInput
{
    public function __construct(
        #[Validate('string|max:100')]
        public ?string $query = null,
        #[Validate('in:name,price,date')]
        public ?string $sortBy = null,
        #[Validate('in:asc,desc')]
        public ?string $sortDir = null,
        #[Validate('integer|min_value:1|max_value:100')]
        public int $limit = 20,                          // ?limit= is 20
        #[Validate('integer|min_value:1')]
        public int $page = 1,
        #[Validate('date:Y-m-d')]
        public ?string $dateFrom = null,
        #[Validate('date:Y-m-d|after:dateFrom')]
        public ?string $dateTo = null,
    ) {
    }
}
```
