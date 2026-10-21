# InputFactory and Inputs

`InputFactory` builds input DTOs from requests and arrays. `Inputs` reads a DTO the way the factory sees it — as an array, by group, by what the request supplied. A DTO itself holds its fields and nothing else.

## Input classes

An input class carries `#[Input]`. Its fields are its constructor parameters, each held by the public property of the same name — usually promoted:

```php
use Solo\Input\Attributes\{Input, Validate};

#[Input]
final readonly class CreateUserInput
{
    public function __construct(
        #[Validate('required|string|max:255')]
        public string $name,
        #[Validate('required|email')]
        public string $email,
        #[Validate('nullable|string')]
        public ?string $bio = null,
    ) {
    }
}
```

- A field with a default is optional; one without is required: when the input lacks it, the field fails `required` — even without a `required` rule. Declare required fields first, as PHP expects.
- The factory calls the constructor once, after the whole input is valid, with named arguments; an absent optional field takes its default. The constructor may check invariants of its own — an exception it throws propagates.
- A `readonly` class is the natural shape: the DTO cannot change after validation. A plain class works too.
- Inheritance works: `#[Input]` on a parent applies to its subclasses, and a subclass constructor may pass inherited fields on to `parent::__construct()` — their attributes stay on the parent's properties.
- Every constructor parameter must have a public property of its name; a field attribute on a property that is not a constructor parameter fails the build, as it would never run.

---

## InputFactory

```php
use Solo\Input\InputFactory;

$factory = new InputFactory(
    validator: $validator,      // Solo\Contracts\Validator\ValidatorInterface
    autoTrim: true,             // trim strings before normalization
    container: $container,      // optional PSR-11 container for handlers
);
```

### fromBody()

```php
public function fromBody(
    string $className,
    ServerRequestInterface $request,
    array $route = [],
    array $context = [],
): object
```

Builds `$className` from the parsed body (POST, PUT, PATCH). Fields without a source attribute read the body; `#[FromRoute]` reads `$route`, `#[FromContext]` reads `$context`. Returns an instance of `$className` — PHPStan and IDEs see the class through the generic signature.

### fromQuery()

```php
public function fromQuery(
    string $className,
    ServerRequestInterface $request,
    array $route = [],
    array $context = [],
): object
```

The same with query parameters as the default source (GET).

### fromArray()

```php
public function fromArray(
    string $className,
    array $data,
    array $route = [],
    array $context = [],
): object
```

The same with a plain array in place of the body — for queue payloads, CLI input, tests.

All three throw `ValidationException` with every error at once, keyed by input path. Misconfiguration throws `ConfigurationException`; a value the application supplied that does not fit its field throws `UnexpectedValueException`.

```php
$input = $factory->fromBody(
    UpdateProductInput::class,
    $request,
    route:   ['id' => $productId],
    context: ['authUserId' => $auth->id()],
);
```

### schema()

```php
public function schema(string $className): array
```

The JSON-serializable input contract of a class, keyed by input key. See [Schema Export](/features/schema).

### register()

```php
public function register(string $className, object $instance): self
```

Registers a normalizer, caster, post-processor or generator instance; it takes precedence over the container's entry for that class.

---

## Inputs

### toArray()

```php
public static function toArray(object $input, bool $onlyProvided = false): array
```

The fields as an array in declaration order, `#[Exclude]` ones left out. A nested input DTO — in a field or a list — becomes an array too. Exporting does not replace DTOs in the original arrays, including entries held by reference; array keys are preserved.

With `onlyProvided: true`, only the fields the request supplied — the change set of a PATCH. It applies to the nested DTOs and `#[Items]` the factory built; a DTO the application passed in `context` is a value supplied as a whole.

```php
// PATCH /products/5  {"name": "Desk", "note": null}
$input = $factory->fromBody(UpdateProductInput::class, $request, route: ['id' => 5]);

Inputs::toArray($input);                      // ['id' => 5, 'name' => 'Desk', 'note' => null, 'price' => null, ...]
Inputs::toArray($input, onlyProvided: true);  // ['id' => 5, 'name' => 'Desk', 'note' => null]
```

### provided()

```php
public static function provided(object $input): array
```

Names of the fields the request supplied, in declaration order: every field whose key was present in its source (body, query, route or context) — an explicit `null` or `""` included — and the generated ones. A field left at its default because the key was absent is not listed.

A DTO created with `new` has nothing provided, and neither has a clone or an unserialized copy: the list is kept beside the instance the factory built, not in its properties — `(array) $input`, `serialize()` and normalizers see only the DTO's own fields. The list describes the original input; clearing the metadata cache does not erase it.

```php
// Body: {"name": "Desk", "note": null}
Inputs::provided($input);   // ['name', 'note']
```

### isProvided()

```php
public static function isProvided(object $input, string $name): bool
```

Whether the request supplied the field. Unlike reading the field, a default does not count: with `public ?string $note = null` absent from the input, `$input->note` is `null` and `isProvided($input, 'note')` is `false`.

```php
if (Inputs::isProvided($input, 'email')) {
    $this->sendVerification($input->email);   // only when the client changed it
}
```

### group()

```php
public static function group(object $input, string $name): array
```

The fields of a `#[Group]` as one flat array:

- Fields are read in declaration order using their current values, including defaults
- Associative array fields: contents are merged by their own keys; nested values are preserved
- Other values — scalars, sequential arrays and objects: added by field name, or by `mapTo` if specified
- Empty arrays and `null` values are skipped; `false`, `0` and `""` are kept
- Throws `LogicException` on a duplicate key
- Unknown groups return `[]`

See [Field Grouping](/features/grouping), including [Repository Criteria](/features/grouping#repository-criteria).

### clearCache()

```php
public static function clearCache(?string $className = null): void
```

Clears the metadata registry shared by `InputFactory` and `Inputs` — for one class, or all when `null`. A test helper for isolating cases within a single PHP process; production code never needs it.

Every `Inputs` method takes an input DTO: another object fails with `ConfigurationException` (it is not an input class).
