# Configuration

## InputFactory Constructor

```php
public function __construct(
    ValidatorInterface $validator,
    bool $autoTrim = true,
    ?ContainerInterface $container = null,
)
```

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$validator` | `ValidatorInterface` | required | Validator instance |
| `$autoTrim` | `bool` | `true` | Auto-trim whitespace from string inputs |
| `$container` | `?Psr\Container\ContainerInterface` | `null` | Resolves normalizer, caster, post-processor and generator classes — see [Dependency Injection](#dependency-injection) |

## Auto Trim

By default, every string the client sends is trimmed before validation. `#[FromContext]` values are the application's and stay as passed:

```php
// Input: "  John Doe  " → Stored as: "John Doe"
#[Validate('required|string')]
public string $name,
```

**Opt a property — or a whole class — out with `#[NoTrim]`:**

```php
#[NoTrim]
public string $snippet,   // whitespace kept as sent
```

**Disable globally:**

```php
$factory = new InputFactory($validator, autoTrim: false);
```

**To trim selected fields when auto-trim is disabled, use a normalizer:**

```php
use Solo\Input\Attributes\Normalize;
use Solo\Input\Contracts\NormalizerInterface;
use Solo\Input\ProcessContext;

final class TrimNormalizer implements NormalizerInterface
{
    public function normalize(mixed $value, ProcessContext $context): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }
}

#[Normalize(TrimNormalizer::class)]
public string $name,
```

A string trimmed to `""` stays `""` for a `string` property. To read it as no value — `null` or the default — add [`#[EmptyToNull]`](/features/field-attribute#emptytonull) to the property or the class.

## Dependency Injection

Normalizer, caster, post-processor and generator classes are resolved by class name, in this order:

1. an instance passed to `register()`;
2. the entry of the PSR-11 container given to the constructor, when `has(Class::class)` is true;
3. a new instance built without arguments.

A class that needs constructor arguments must come from 1 or 2 — otherwise its first use throws `ConfigurationException::handlerNotInstantiable`. A registered instance, or one built without arguments, is kept for the factory's lifetime; a container entry is fetched from the container on every use, so the container's own scoping — shared, built per call, request-scoped — applies even when the factory outlives a request.

### From a PSR-11 container

Pass your application's container — handlers with dependencies come from it, no wiring per class:

```php
$factory = new InputFactory($validator, container: $container);

// SequenceGenerator(Connection $connection) is built by the container
#[Generator(SequenceGenerator::class)]
public int $id,
```

### Registering instances

Register normalizer/caster/post-processor/generator instances with constructor dependencies:

```php
final class SequenceGenerator implements GeneratorInterface
{
    public function __construct(private readonly Connection $connection) {}

    public function generate(array $options = []): int
    {
        return $this->connection->getNextId($options['table']);
    }
}

$factory = new InputFactory($validator);
$factory->register(SequenceGenerator::class, new SequenceGenerator($connection));
```

`register()` returns `$this` for chaining:

```php
$factory
    ->register(SequenceGenerator::class, new SequenceGenerator($db))
    ->register(SlugProcessor::class, new SlugProcessor($transliterator));
```

Registered (and lazily-created) instances are reused across all subsequent `from*()` calls on the same factory. The factory is designed for classic PHP-FPM, where its lifetime equals one request — keep `NormalizerInterface` / `CasterInterface` / `PostProcessorInterface` / `GeneratorInterface` implementations free of per-request mutable state.

## Factory methods

| Method | Default source | Input |
|---|---|---|
| `fromBody(class, request, route, context)` | `Body` | `$request->getParsedBody()` |
| `fromQuery(class, request, route, context)` | `Query` | `$request->getQueryParams()` |
| `fromArray(class, data, route, context)` | `Body` | raw `array $data` |

All three accept the same optional `$route` and `$context` arrays. The "default source" is what fields without a source attribute read from; fields with `#[FromRoute]`, `#[FromContext]` or `#[FromBody]` always use that source instead.

A fourth method, `schema(class)`, is read-only — it reflects an input class into a JSON-serializable validation contract without processing any input. See [Schema Export](/features/schema).

### fromBody()

POST/PUT/PATCH requests. Reads body via `$request->getParsedBody()`:

```php
$dto = $factory->fromBody(CreateProductInput::class, $request);
```

### fromQuery()

GET requests. Reads from `$request->getQueryParams()`:

```php
$dto = $factory->fromQuery(SearchInput::class, $request);
```

### fromArray()

Process raw arrays without a PSR-7 request — useful for tests, CLI commands, or non-HTTP sources:

```php
$dto = $factory->fromArray(OrderItemInput::class, [
    'product' => 'Widget',
    'quantity' => '3',
    'price' => '9.99',
]);
```

## Route + context arguments

`fromBody()` / `fromQuery()` / `fromArray()` accept two optional bags besides the request itself:

| Argument | Purpose | Consumed by |
|---|---|---|
| `$route` | URL path parameters (`/products/{id}` → `['id' => 5]`) | `#[FromRoute]` + `ProcessContext::$route` |
| `$context` | Generic application context (auth user, tenant, feature flags) | `#[FromContext]` + `ProcessContext::$context` |

They are NOT merged into the body bag. Each lives in its own slot — collisions between body keys, route keys and context keys are impossible by construction.

For values that don't fit one of the four bags (Body, Query, Route, Context) — request headers, cookies, file uploads — extract them in the controller and pass them via `route: [...]` or `context: [...]`.

### Example: route param as a DTO property

```php
use Solo\Input\Attributes\{Validate};
use Solo\Input\Attributes\Source\FromRoute;

#[Input]
final class UpdateProductInput
{
    public function __construct(
        #[FromRoute]
        #[Validate('required|integer|exists:products,id')]
        public int $id,
        #[Validate('required|string|max:255')]
        public string $name,
    ) {
    }
}

$dto = $factory->fromBody(
    UpdateProductInput::class,
    $request,
    route: ['id' => 123],
);

$dto->id;   // 123 — pulled from $route
$dto->name; // from body
```

### Example: context value as a DTO property

```php
use Solo\Input\Attributes\Source\FromContext;

#[Input]
final class CreatePostInput
{
    public function __construct(
        #[FromContext('authUserId')]
        #[Validate('required|integer')]
        public int $authorId,
        #[Validate('required|string')]
        public string $title,
    ) {
    }
}

$dto = $factory->fromBody(
    CreatePostInput::class,
    $request,
    context: ['authUserId' => $auth->id()],
);
```

### Cross-field references in validation rules

The factory does not transform rule strings. Cross-field references in rules (e.g. `unique:users,email,{id}`) are the validator's concern.

A typical validator implementation resolves `{id}` against the validation payload — which contains every field present in the input, including those marked `#[FromRoute]` / `#[FromContext]`. So declare the referenced value as a field:

```php
#[Input]
final class UpdateUserInput
{
    public function __construct(
        #[FromRoute]
        #[Validate('required|integer|exists:users,id')]
        public int $id,
        #[Validate('required|email|unique:users,email,{id}')]
        public string $email,
    ) {
    }
}

$dto = $factory->fromBody(
    UpdateUserInput::class,
    $request,
    route: ['id' => 123],
);
// Validator sees: data = ['id' => 123, 'email' => '...'], and resolves {id} → 123
```

### Inside a `PostProcessorInterface`

```php
use Solo\Input\Contracts\PostProcessorInterface;
use Solo\Input\ProcessContext;

/** @implements PostProcessorInterface<string> */
final class SlugWithIdProcessor implements PostProcessorInterface
{
    public function postProcess(mixed $value, ProcessContext $context): string
    {
        $id = $context->route['id'] ?? '';
        $tenant = $context->context['tenant'] ?? '';
        return $value . '-' . $id . '-' . $tenant;
    }
}
```

### Nested items

Route is **not** propagated into nested items processed via `#[Items]` (items describe outer-resource children, not the outer resource). Context IS propagated, so item normalizers, casters and post-processors can read `ProcessContext::$context`.

## Input Classes

An input class is any class marked `#[Input]`; its constructor parameters are its fields:

```php
use Solo\Input\Attributes\{Input, Validate};

#[Input]
final readonly class MyInput
{
    public function __construct(
        #[Validate('required|string')]
        public string $name,
    ) {
    }
}
```

There is no base class. The readers live in `Inputs`:

- `Inputs::toArray($input, onlyProvided: false)` — the fields as an array (`#[Exclude]` ones left out; with `onlyProvided`, also those the request did not supply)
- `Inputs::provided($input)` / `Inputs::isProvided($input, $name)` — which fields the request supplied (PATCH)
- `Inputs::group($input, $name)` — the fields of a `#[Group]`
- `Inputs::clearCache()` — drop the metadata cache (test helper)

See [InputFactory and Inputs](/api/input-factory).
