# Solo Input

[![Latest Version on Packagist](https://img.shields.io/packagist/v/solophp/input.svg)](https://packagist.org/packages/solophp/input)
[![PHP Version](https://img.shields.io/badge/PHP-8.3%2B-8892BF.svg)](https://php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Typed, validated input DTOs for PHP 8.3+. Mark a class `#[Input]` and declare its fields in the constructor: the factory reads them from the request, validates and casts the whole input, then calls the constructor. You get a complete, typed object, and as a `readonly` class it cannot change.

## Installation

```bash
composer require solophp/input
```

Requires PHP 8.3+, a PSR-7 implementation and a validator implementing `Solo\Contracts\Validator\ValidatorInterface`, such as [solophp/validator](https://github.com/SoloPHP/Validator).

## Example

```php
use Solo\Input\Attributes\Input;
use Solo\Input\Attributes\Source\FromRoute;
use Solo\Input\Attributes\Validate;
use Solo\Input\InputFactory;

#[Input]
final readonly class UpdateProductInput
{
    public function __construct(
        #[FromRoute]
        #[Validate('required|integer')]
        public int $id,

        #[Validate('required|string|max:255')]
        public string $name,

        #[Validate('required|numeric|min_value:0')]
        public float $price,

        #[Validate('integer|min_value:0')]
        public int $stock = 0,
    ) {
    }
}

$factory = new InputFactory($validator);

// PUT /products/{id}
$input = $factory->fromBody(UpdateProductInput::class, $request, route: ['id' => $productId]);

$input->id;    // int, from the URL path
$input->name;  // string, from the body
$input->stock; // int, 0 when the client left it out
```

Invalid input throws one `ValidationException` with every error, keyed by the field the client sent.

## Documentation

**[Full Documentation](https://solophp.github.io/Input/)**

- [Installation](https://solophp.github.io/Input/guide/installation)
- [Quick Start](https://solophp.github.io/Input/guide/quick-start)
- [Configuration](https://solophp.github.io/Input/guide/configuration)
- [Attributes](https://solophp.github.io/Input/features/field-attribute)
- [Type Casting](https://solophp.github.io/Input/features/type-casting)
- [Processing Stages](https://solophp.github.io/Input/features/processors)
- [Generators](https://solophp.github.io/Input/features/generators)
- [Nested Objects & Items](https://solophp.github.io/Input/features/nested-items)
- [Field Grouping](https://solophp.github.io/Input/features/grouping)
- [Validation](https://solophp.github.io/Input/features/validation)
- [Schema Export](https://solophp.github.io/Input/features/schema)
- [API Reference](https://solophp.github.io/Input/api/input-factory)

## License

MIT. See [LICENSE](LICENSE).
