# Schema Export

`InputFactory::schema()` reflects a request class into a JSON-serializable description of its input contract. It does **not** read or process any HTTP input — it only inspects the class's cached metadata, so it is safe to call anywhere (including outside a request).

The point: ship validation rules to the frontend from a single source of truth instead of duplicating them by hand.

```php
public function schema(string $className): array
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$className` | `class-string` | An `#[Input]` class to describe |

**Returns:** a map of **input key** → field schema — the same keys the client sends and `ValidationException` reports errors under.

## Example

```php
use Solo\Input\Attributes\{DateFormat, Items, Validate};
use Solo\Input\Attributes\Source\{FromBody, FromContext, FromRoute};
use Solo\Input\Attributes\Input;

enum Visibility: string
{
    case Public = 'public';
    case Hidden = 'hidden';
}

#[Input]
final class UpdateProductInput
{
    public function __construct(
        #[FromRoute]
        #[Validate('required|integer')]
        public int $id,
        #[Validate('required|string|max:255')]
        public string $name,
        #[FromContext('authUserId')]
        public int $updatedBy,          // supplied by the application — not part of the schema
        #[FromBody('meta.status')]
        #[Validate('in:draft,published')]
        public ?string $status = null,
        #[Validate('string|required_if:status,published')]
        public ?string $slug = null,
        #[DateFormat('Y-m-d')]
        public ?DateTimeImmutable $publishAt = null,
        public Visibility $visibility = Visibility::Public,
        /** @var VariantInput[] */
        #[Items(VariantInput::class)]
        public array $variants = [],
    ) {
    }
}

$schema = $factory->schema(UpdateProductInput::class);
```

`$schema` is (rule lists shortened):

```php
[
    'id' => [
        'property'   => 'id',
        'source'     => 'route',
        'type'       => 'int',
        'nullable'   => false,
        'required'   => true,
        'requiredIf' => null,
        'hasDefault' => false,
        'default'    => null,
        'rules'      => [['name' => 'required', 'args' => []], ['name' => 'integer', 'args' => []]],
    ],
    'name' => [
        'property' => 'name', 'source' => null, 'type' => 'string', 'nullable' => false,
        'required' => true, 'requiredIf' => null, 'hasDefault' => false, 'default' => null,
        'rules' => [/* required, string, max:255 */],
    ],
    'meta.status' => [
        'property' => 'status', 'source' => 'body', 'type' => 'string', 'nullable' => true,
        'required' => false, 'requiredIf' => null, 'hasDefault' => true, 'default' => null,
        'rules' => [/* in:draft,published */],
    ],
    'slug' => [
        // … 'requiredIf' references the input key of `status`:
        'requiredIf' => ['field' => 'meta.status', 'value' => 'published'],
    ],
    'publishAt' => [
        // … 'type' => 'DateTimeImmutable',
        'format' => 'Y-m-d',
    ],
    'visibility' => [
        // … 'type' => 'App\Visibility', 'hasDefault' => true,
        'default' => 'public',
        'cases'   => ['public', 'hidden'],
    ],
    'variants' => [
        // … 'type' => 'array',
        'items' => [
            'sku' => [/* field schema of VariantInput::$sku */],
        ],
    ],
]
```

## Field schema

| Key | Type | Description |
|-----|------|-------------|
| `property` | `string` | The PHP property name |
| `source` | `'body' \| 'route' \| null` | Where the client sends it; `null` = the handle method's default bag (body or query) |
| `type` | `?string` | Declared PHP type (`null` when untyped) |
| `nullable` | `bool` | Whether the type allows `null`: sent with [no value](/features/validation#no-value), the field is `null` — unless a presence rule in `rules` (`required`, `filled`, `required_if`) refuses it |
| `required` | `bool` | Whether the client must send it: a `required` rule, or no default — absent, it fails `required` |
| `requiredIf` | `{field, value} \| null` | First well-formed `required_if`; `field` is the referenced field's input key |
| `hasDefault` | `bool` | Whether the property declares a default |
| `default` | `mixed` | The default (an enum case as its value); `null` without one |
| `rules` | `list<{name, args}>` | Full normalized rule list |
| `format` | `string` | Only for `#[DateFormat]` dates |
| `cases` | `list<int\|string>` | Only for `BackedEnum` properties: the allowed values |
| `cast` | `string` | Only when the built-in cast differs from `type`: `json` (any JSON value, stored encoded), `decimal` (a number, stored as a plain decimal string), or the `#[Cast]` of a union / `mixed` property |
| `emptyToNull` | `true` | Only with `#[EmptyToNull]`: a blank string is read as no value |
| `noTrim` | `true` | Only with `#[NoTrim]`: the string is kept as sent instead of auto-trimmed |
| `fields` | field schemas | Only for a nested DTO property |
| `items` | field schemas | Only for an `#[Items]` property: the schema of one element |
| `also` | `list<field schema>` | Only when several properties read this input key: the others, in declaration order |

## Notes

### Only what the client sends

Generated (`#[Generator]`) and `#[FromContext]` properties are left out — the client never sends them. `#[Exclude]` describes output (`toArray()`), not input, so excluded properties are included.

### `required` vs `requiredIf`

`required` reflects the **literal** `required` rule only. Conditional requiredness lives in `requiredIf` — the first well-formed `required_if`, as `{field, value}` — and the rule itself is still present in `rules`. A `required_if` missing an argument is treated as absent.

### Shared input keys

Two properties may read the same input — `#[FromRoute]` on `$id` and `#[FromRoute('id')]` on `$productId`, say. The field is described by the first one in declaration order; the others follow under `also`, each with its own `property`, `rules` and the rest. The client has to satisfy all of them, as validation errors for the key are merged too.

### Nested schemas and recursion

`fields` and `items` hold the full schema of the nested class. A class that recurs on its own nesting path (a category tree with `#[Items(CategoryInput::class)]` inside `CategoryInput`) is referenced as `['$ref' => 'App\Requests\CategoryInput']` instead of being expanded again.

### Rule normalization

Each rule string is split with the same semantics the factory uses internally:

- the raw string is split on `|`;
- `name` is the part before the first `:`;
- `args` is the comma-split remainder (an empty list when there is no `:`).

### JSON-safe by construction

Every emitted value is a scalar or array — no `ReflectionProperty`, enums, objects, or closures leak through — so the result round-trips losslessly through `json_encode` / `json_decode`.

### Changes from 3.x

The schema is keyed by input key (3.x used the property name), lists only client inputs (3.x included context and generated properties), dropped the `exclude` flag, and gained `property`, `default`, `format`, `cases`, `cast`, `emptyToNull`, `noTrim`, `fields`, `items` and `also`.
