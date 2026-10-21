---
layout: home

hero:
  name: Solo Input
  text: Typed, validated input DTOs
  tagline: Turn HTTP input into immutable, strictly typed objects built through their constructor — validated, cast and processed by attributes.
  image:
    src: /logo.svg
    alt: Solo Input
  actions:
    - theme: brand
      text: Get Started
      link: /guide/installation
    - theme: alt
      text: View on GitHub
      link: https://github.com/solophp/input

features:
  - icon: 🧱
    title: Plain, immutable classes
    details: 'An input DTO is a #[Input] class whose constructor parameters are its fields — a final readonly class, no base class to extend. The factory calls the constructor once the whole input is valid.'
  - icon: 🎯
    title: Explicit source binding
    details: 'The default source comes from the factory method (body / query). #[FromRoute], #[FromContext] and #[FromBody] override it per field. No implicit merging.'
  - icon: 🔄
    title: Lossless type casting
    details: Driven by the declared type — int, float, bool, string, array, DateTime, backed enums and custom casters. "12abc" for an int is a validation error, not 12.
  - icon: ✅
    title: All errors at once
    details: Rules go verbatim to your ValidatorInterface; a field without a default that the input lacks fails required. Errors come back together, keyed by the input path — address.city, lines.0.qty.
  - icon: 🔧
    title: Processing stages
    details: 'Normalize raw input, cast it to the declared type, post-process decoded values, generate the rest — each with its own focused attribute.'
  - icon: 🪆
    title: Nested DTOs
    details: 'A field typed with an #[Input] class is a nested object; #[Items] makes a list of them.'
  - icon: 🩹
    title: PATCH-ready
    details: 'Inputs::provided() tells a field the client sent — even as null — from one left at its default; Inputs::toArray($input, onlyProvided: true) is the change set.'
  - icon: 📐
    title: Schema export
    details: schema() emits the JSON-serializable input contract keyed like the errors — types, requiredness, rules, defaults, date formats, enum cases, nested schemas.
---

<style>
:root {
  --vp-home-hero-name-color: transparent;
  --vp-home-hero-name-background: linear-gradient(135deg, #10b981 0%, #3b82f6 100%);
  --vp-home-hero-image-background-image: linear-gradient(135deg, #10b98130 0%, #3b82f630 100%);
  --vp-home-hero-image-filter: blur(44px);
}

.VPHero .VPImage {
  max-width: 200px;
  max-height: 200px;
}
</style>

## Quick Example

```php
use Solo\Input\Attributes\{Generator, Input, Validate};
use Solo\Input\Attributes\Source\FromRoute;

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
        #[Generator(UuidGenerator::class)]
        public string $revision,
        #[Validate('integer|min_value:0')]
        public int $stock = 0,
    ) {
    }
}

$input = $factory->fromBody(UpdateProductInput::class, $request, route: ['id' => $productId]);

$input->id;        // int — from the URL path
$input->name;      // string — from the body
$input->price;     // float — cast without loss
$input->revision;  // string — generated
$input->stock;     // int — 0 when the client left it out
```

## Installation

```bash
composer require 'solophp/input:^1.0@RC'
```

1.0 is a release candidate: the API may still change before 1.0.0.

**Requirements:** PHP 8.3+, a PSR-7 implementation, a validator implementing `Solo\Contracts\Validator\ValidatorInterface`.
