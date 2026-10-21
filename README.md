# Solo Input

Typed, validated input DTOs for PHP 8.3+. An input DTO is a plain `#[Input]` class whose constructor parameters are its fields; the factory reads them from the request, validates and casts the whole input, then calls the constructor — so the object you get is complete, typed and, as a `readonly` class, unchangeable.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/input.svg)](https://packagist.org/packages/solophp/input)
[![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

## Features

- **Plain, immutable classes** — no base class: mark the class `#[Input]`, declare its fields in the constructor, make it `final readonly`. A field with a default is optional; one without is required.
- **Per-field source binding** — the default source comes from the factory method (`fromBody` → body, `fromQuery` → query); `#[FromRoute]`, `#[FromContext]` and `#[FromBody('a.b')]` override it per field. No implicit merging.
- **Attribute-composed fields** — each concern is its own focused attribute: `#[Validate]`, `#[DateFormat]`, `#[Caster]`, `#[PostProcess]`, `#[Generator]`, `#[Items]`, …
- **Lossless type casting** — driven by the declared type: int, float, bool, string, array, `DateTime` / `DateTimeImmutable`, backed enums and custom casters. `"12abc"` for an int, `"maybe"` for a bool or an unknown enum value is a validation error, never a silently coerced value.
- **Validation rules** — passed verbatim to your `ValidatorInterface`; every field present in the input — route and context values included — lands in the validation payload, so cross-field rules work without special syntax.
- **All errors at once** — validator errors, cast failures, missing fields and nested errors come back in one `ValidationException`, keyed by the input the client sent (`display_name`, `customer.id`, `lines.0.qty`). Post-processors, generators and the constructor run only for valid input.
- **PATCH-ready** — `Inputs::provided()` tells a field the client sent, even as `null`, from one left at its default; `Inputs::toArray($input, onlyProvided: true)` is the change set.
- **Processing stages** — `#[Normalize]` prepares raw input, `#[Caster]` creates its type, `#[PostProcess]` transforms decoded values after the whole tree is valid.
- **Nested DTOs** — a field typed with an `#[Input]` class is a nested object, `#[Items]` makes a list of them.
- **PSR-11 ready** — normalizers, casters, post-processors and generators with dependencies come from your container.
- **Fail-fast** — misconfigurations throw `ConfigurationException` at metadata-build time, nested classes included.
- **Schema export** — `schema()` emits the JSON-serializable input contract: per field type, nullability, requiredness, rules, default, date format, enum cases and nested schemas.

## Installation

```bash
composer require 'solophp/input:^1.0@RC'
```

1.0 is a release candidate: the API may still change before 1.0.0.

## Quick Example

```php
use Solo\Input\Attributes\{Exclude, Input, Validate};
use Solo\Input\Attributes\Source\{FromContext, FromRoute};

#[Input]
final readonly class UpdateProductInput
{
    public function __construct(
        // PUT /products/{id}
        #[FromRoute]
        #[Validate('required|integer|exists:products,id')]
        public int $id,

        #[Validate('required|string|max:255')]
        public string $name,

        #[Validate('required|numeric|min_value:0')]
        public float $price,

        #[FromContext('authUserId')]
        #[Validate('required|integer')]
        public int $updatedBy,

        #[Validate('required|string|min:8')]
        #[Exclude]              // validated, but left out of Inputs::toArray()
        public string $confirmPassword,

        #[Validate('integer|min_value:0')]
        public int $stock = 0,
    ) {
    }
}

// Controller
$input = $factory->fromBody(
    UpdateProductInput::class,
    $request,
    route:   ['id' => $productId],
    context: ['authUserId' => $auth->id()],
);

$input->id;              // int — from the URL path
$input->name;            // string — from the body
$input->updatedBy;       // int — from the application context
Inputs::toArray($input); // ['id' => …, 'name' => …, 'price' => …, 'updatedBy' => …, 'stock' => …]
```

`$factory` is a `Solo\Input\InputFactory` built with your validator: `new InputFactory($validator)`.

## Input classes

- Mark the class `#[Input]`. Its constructor parameters are its fields, each held by the public property of the same name — usually promoted.
- A field with a default is optional; one without is required: when the input lacks it, it fails `required`. PHP expects required parameters first.
- The factory calls the constructor once, after the whole input is valid, with named arguments. The constructor may check invariants of its own.
- Inheritance works: `#[Input]` on a parent applies to its subclasses, and a subclass constructor may pass inherited fields to `parent::__construct()`.

## Attributes

| Attribute | Purpose |
|---|---|
| `#[Input]` | Marks an input class — on the class |
| `#[Validate('rules')]` | Validation rules (passed to your `ValidatorInterface`) |
| `#[FromRoute(?string $key = null)]` | Read from the `$route` argument (URL path params) |
| `#[FromContext(string $key)]` | Read from the `$context` argument (auth user, tenant, …) |
| `#[FromBody(string $path)]` | Read from a nested body path (`customer.id`); flat keys may also be remapped this way |
| `#[Cast('int'\|'float'\|'bool'\|'string'\|'array'\|'json')]` | Explicit cast (union / `mixed` fields); `json` encodes into a string |
| `#[DateFormat('Y-m-d')]` | Fixed input format for a date field — the class follows the type |
| `#[Caster(MoneyCaster::class)]` | Custom `CasterInterface` implementation |
| `#[EmptyToNull]` | A blank string is no value — `null`, the default or `required` (field or class) |
| `#[NoTrim]` | Auto-trim skips it — the string is kept as sent (field or class) |
| `#[Normalize(MyNormalizer::class, config: [...])]` | Normalize present input before validation |
| `#[PostProcess(MyProc::class, config: [...])]` | Transform decoded values after the whole input tree is valid |
| `#[Generator(UuidGenerator::class, options: [...])]` | The value is generated, not read from any source |
| `#[Items(OrderLineInput::class)]` | The field is a list of nested DTOs |
| `#[Group('criteria', mapTo: 'price >=')]` | Group membership + remap for `Inputs::group()` |
| `#[Exclude]` | `Inputs::toArray()` skips the field (still validated and processed) |

Fields without a source attribute read from the default-source bag (body for `fromBody`/`fromArray`, query for `fromQuery`) by name. For values that don't fit the four bags (body, query, route, context) — request headers, cookies, file uploads — extract them in the controller and pass them via `route: [...]` or `context: [...]`.

## Reading a DTO

A DTO holds its fields and nothing else; `Solo\Input\Inputs` reads it the way the factory sees it:

```php
Inputs::toArray($input);                      // every field but #[Exclude] ones; nested DTOs as arrays
Inputs::toArray($input, onlyProvided: true);  // only what the client sent — the change set of a PATCH
Inputs::provided($input);                     // ['name', 'note'] — sent, even as null
Inputs::isProvided($input, 'note');
Inputs::group($input, 'criteria');            // the #[Group('criteria')] fields as one array
```

## Schema introspection

`schema()` reflects an input class into a JSON-serializable description of its input contract — without touching any HTTP input. Hand it to the frontend so validation rules live in one place.

```php
$schema = $factory->schema(UpdateProductInput::class);

// [
//   'id' => [
//     'property' => 'id', 'source' => 'route', 'type' => 'int', 'nullable' => false,
//     'required' => true, 'requiredIf' => null, 'hasDefault' => false, 'default' => null,
//     'rules' => [['name' => 'required', 'args' => []], ['name' => 'integer', 'args' => []], …],
//   ],
//   …
// ]
```

Fields are keyed by their **input key** — the key the client sends and errors are reported under. Generated and `#[FromContext]` fields are left out. A field without a default is `required`; conditional requiredness lives in `requiredIf`. See [Schema Export](https://solophp.github.io/Input/features/schema).

## Documentation

**[Full Documentation](https://solophp.github.io/Input/)**

- [Installation](https://solophp.github.io/Input/guide/installation)
- [Quick Start](https://solophp.github.io/Input/guide/quick-start)
- [Configuration](https://solophp.github.io/Input/guide/configuration)
- [Attributes](https://solophp.github.io/Input/features/field-attribute)
- [Type Casting](https://solophp.github.io/Input/features/type-casting)
- [Processing stages](https://solophp.github.io/Input/features/processors)
- [Generators](https://solophp.github.io/Input/features/generators)
- [Nested Objects & Items](https://solophp.github.io/Input/features/nested-items)
- [Field Grouping](https://solophp.github.io/Input/features/grouping)
- [Validation](https://solophp.github.io/Input/features/validation)
- [API Reference](https://solophp.github.io/Input/api/input-factory)

## Requirements

- PHP 8.3+
- PSR-7 HTTP Message implementation
- PSR-11 container (optional) — for normalizers, casters, post-processors and generators with constructor dependencies
- Validator implementing `Solo\Contracts\Validator\ValidatorInterface`

We recommend [solophp/validator](https://github.com/SoloPHP/Validator).

## Public API

`InputFactory`, `Inputs`, the attributes, the contracts (`NormalizerInterface`, `CasterInterface`, `PostProcessorInterface`, `GeneratorInterface`), `ProcessContext`, `FieldSource` and the exceptions are the public API and follow semantic versioning. Classes marked `@internal` — metadata (`Cache\*`), `Casters\BuiltInCaster`, `Grouping\GroupBuilder`, `Pipeline\*`, `Schema\SchemaBuilder`, `RuleParser`, `InputBags` — may change in any release.

## License

MIT License. See [LICENSE](LICENSE) for details.
