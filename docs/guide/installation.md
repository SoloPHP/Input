# Installation

## Requirements

- PHP 8.3 or higher
- PSR-7 HTTP Message implementation
- Validator implementing `Solo\Contracts\Validator\ValidatorInterface`
- Optional: a PSR-11 container for normalizers, casters, post-processors and generators with constructor dependencies

## Composer

Install the package via Composer:

```bash
composer require solophp/input
```

## Dependencies

```json
{
    "require": {
        "php": "^8.3",
        "psr/container": "^2.0",
        "psr/http-message": "^2.0",
        "solophp/contracts": "^1.3"
    }
}
```

## Validator

The package requires a validator implementing `Solo\Contracts\Validator\ValidatorInterface`. `validate()` gets the rules of every field, also of one the client did not send — its key is then missing from the data, and the validator decides what that means. We recommend [solophp/validator](https://github.com/SoloPHP/Validator), which checks an absent field by its presence rules only:

```bash
composer require solophp/validator
```

A field without a default that the input lacks fails `required` whatever the validator decides — the constructor cannot be called without it.

## Basic Setup

```php
use Solo\Input\InputFactory;
use Solo\Validator\Validator;

$factory = new InputFactory(new Validator());
```

## Versioning

Solo Input follows semantic versioning. Its public API is what you write against: `InputFactory`, `Inputs`, the attributes, the contracts (`NormalizerInterface`, `CasterInterface`, `PostProcessorInterface`, `GeneratorInterface`), `ProcessContext`, `FieldSource` and the exceptions. Classes marked `@internal` — metadata, the pipeline, the built-in caster, the group and schema builders — are implementation details and may change in any release.

## Next Steps

- [Quick Start](/guide/quick-start) — Create your first input class
- [Configuration](/guide/configuration) — Sources, auto-trim and handler dependencies
