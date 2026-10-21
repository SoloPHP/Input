# Quick Start

## Declare an Input Class

Mark the class `#[Input]` and declare its fields as constructor parameters. A `final readonly` class keeps the DTO unchangeable once it is built.

```php
<?php

declare(strict_types=1);

namespace App\Inputs;

use Solo\Input\Attributes\{Input, Validate};

#[Input]
final readonly class CreateProductInput
{
    public function __construct(
        #[Validate('required|string|max:255')]
        public string $name,
        #[Validate('required|numeric|min_value:0')]
        public float $price,
        #[Validate('integer|min_value:0')]
        public int $stock = 0,
        #[Validate('nullable|string')]
        public ?string $description = null,
    ) {
    }
}
```

## Build It from the Request

`InputFactory` reads the PSR-7 request, validates and casts every field, then calls the constructor:

```php
use App\Inputs\CreateProductInput;
use Solo\Input\InputFactory;
use Solo\Input\Exceptions\ValidationException;

final class ProductController
{
    public function __construct(
        private readonly InputFactory $factory,
        private readonly ProductService $products,
    ) {
    }

    public function store(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $input = $this->factory->fromBody(CreateProductInput::class, $request);
        } catch (ValidationException $e) {
            return $this->json(['errors' => $e->getErrors()], 422);
        }

        $this->products->create($input);

        return $this->json(['status' => 'created'], 201);
    }
}
```

## Reading the Input

```php
$input = $factory->fromBody(CreateProductInput::class, $request);

$input->name;                           // typed field access
$input->description;                    // null when the client left it out

Inputs::isProvided($input, 'description');  // was it sent — even as null?
Inputs::toArray($input);                    // every field but #[Exclude] ones
Inputs::toArray($input, onlyProvided: true);  // only what the client sent
```

Every field is initialized: the constructor ran with the client's values and the defaults of the fields it left out. There is no "missing" state to check before reading a field.

## Required vs Optional Fields

```php
// Required: no default — absent fails `required`
#[Validate('string')]
public string $username,

// Required, but may be null: the key must be sent
public ?string $nickname,

// Optional: absent takes the default
#[Validate('nullable|string')]
public ?string $bio = null,

#[Validate('integer')]
public int $page = 1,

// No #[Validate] — the factory reads and casts the value, the validator never sees a rule for it
public ?string $internal = null,
```

PHP expects required parameters before optional ones, so declare the fields without defaults first. A field required only under a condition (`required_if`) is optional to PHP: give it a default and let the rule decide.

::: tip Null vs Empty String
The factory distinguishes absent fields, explicit `null` and empty string `""`. See [Validation — Absent vs Null vs Empty String](/features/validation#absent-vs-null-vs-empty-string).
:::

## Next Steps

- [Configuration](/guide/configuration) — `fromBody` / `fromQuery` / route and context
- [Type Casting](/features/type-casting) — Automatic type conversion
- [Validation](/features/validation) — Validation rules and messages
- [Processing stages](/features/processors) — Normalize, cast and post-process
