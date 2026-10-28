# Nested Objects and Items

A field typed with an `#[Input]` class is a **nested object**; `#[Items]` makes an array field a **list** of them. Either way the child DTO runs its full pipeline — read, normalize, validate, decode, post-process, generate — and its errors come back under the path the client used.

## Nested Objects

No attribute is needed — the type says it:

```php
#[Input]
final class AddressInput
{
    public function __construct(
        #[Validate('required|string')]
        public string $city,
        #[Validate('required|string')]
        public string $zip,
    ) {
    }
}

#[Input]
final class ShipmentInput
{
    public function __construct(
        #[Validate('required|string')]
        public string $carrier,
        public AddressInput $address,            // no default: absent or null → 'required'
        public ?AddressInput $billing = null,     // optional
    ) {
    }
}
```

```php
$dto = $factory->fromBody(ShipmentInput::class, $request);
// {"carrier": "ups", "address": {"city": "Kyiv", "zip": "01001"}}

$dto->address->city;   // 'Kyiv'
Inputs::toArray($dto);       // ['carrier' => 'ups', 'address' => ['city' => 'Kyiv', 'zip' => '01001'], 'billing' => null]
```

Errors use the field path: `address.zip` for a field of the nested object, `address` itself (`['rule' => 'array']`) when the value is not an object. `""` is no value — `null`, the default, or `required` — for a nested object and an `#[Items]` list alike; an empty list is `[]`. A `#[Normalize]` on the field runs before validation and can turn a JSON string into the object to read. Nested objects can contain `#[Items]` lists and further nested objects (`from.stock.0.quantity`).

The nested class must be marked `#[Input]` and be concrete; it is validated together with its parent when metadata is built. A field typed with a class that is not an input class needs a `#[Caster]`, a `#[Generator]` or `#[FromContext]`.

Relative types `self` and `parent` resolve against the class declaring the
property, including properties a parent class declares. A `?self` link can therefore describe
a recursive DTO; schema export uses `$ref` when the class recurs.

A `#[FromContext]` field typed with an input class is not built from input: it receives the object the application passes in `context`, as it is. Likewise, `#[Items]` on a `#[FromContext]` list declares the class of the DTOs the application built — each one is checked, none is built from an array; anything else throws `UnexpectedValueException`.

## Item Lists

`#[Items]` validates and processes arrays of nested objects through a referenced input class.

When building items from input, the resulting DTO array is a list in input
iteration order, even if the input uses sparse numeric or string keys. Error
paths retain the original keys: an invalid item at key `7` reports `items.7`,
and one at key `last` reports `items.last`. Post-processors receive the completed
list; their returned arrays and context-supplied arrays are checked without
reindexing.

## Basic Usage

Define a child input class and reference it via `#[Items]`:

```php
use Solo\Input\Attributes\{Validate, Items};
use Solo\Input\Attributes\Input;

#[Input]
final class OrderItemInput
{
    public function __construct(
        #[Validate('required|string|max:255')]
        public string $product,
        #[Validate('required|integer|min_value:1')]
        public int $quantity,
        #[Validate('required|numeric|min_value:0')]
        public float $price,
    ) {
    }
}

#[Input]
final readonly class CreateOrderInput
{
    /**
     * @param list<OrderItemInput> $items
     */
    public function __construct(
        #[Validate('required|string')]
        public string $customer,
        #[Validate('array|min:1')]
        #[Items(OrderItemInput::class)]
        public array $items,
    ) {
    }
}
```

Each element in the `items` array goes through the full processing pipeline of the referenced input class: normalization, validation, casting, post-processing, generation and its constructor.

## Input Format

```json
{
    "customer": "John Doe",
    "items": [
        {"product": "Widget", "quantity": 3, "price": "9.99"},
        {"product": "Gadget", "quantity": 1, "price": "24.50"}
    ]
}
```

After processing:

```php
$dto = $factory->fromBody(CreateOrderInput::class, $request);

$dto->items[0]->product;  // 'Widget'
$dto->items[0]->quantity; // 3
$dto->items[0]->price;    // 9.99
$dto->items[1]->product;  // 'Gadget'

Inputs::toArray($dto);
// ['customer' => 'John Doe', 'items' => [
//     ['product' => 'Widget', 'quantity' => 3, 'price' => 9.99],
//     ['product' => 'Gadget', 'quantity' => 1, 'price' => 24.50],
// ]]
```

## Validation Errors

Validation errors from nested items use dot-notation with the item index:

```php
try {
    $dto = $factory->fromBody(CreateOrderInput::class, $request);
} catch (ValidationException $e) {
    $e->getErrors();
    // [
    //     'items.0.product' => [['rule' => 'required']],
    //     'items.1.quantity' => [['rule' => 'min', 'params' => ['1']]],
    // ]
}
```

Everything is validated before throwing: the parent's own fields, every item, and every `#[Items]` property. A single `ValidationException` carries all errors, in declaration order — each items property is followed by its nested errors:

```php
$e->getErrors();
// [
//     'customer'         => [['rule' => 'required']],
//     'items.0.product'  => [['rule' => 'required']],
//     'items.1.quantity' => [['rule' => 'min', 'params' => ['1']]],
// ]
```

The order of the stages for a list is under [Processing Pipeline](#processing-pipeline).

The item class is validated together with its parent when metadata is built: a misconfigured item class fails the parent's first `from*()` or `schema()` call even if the request carries no items. Self-referencing trees (`#[Items(CategoryInput::class)]` inside `CategoryInput`) are supported.

Non-array elements produce a dedicated error:

```php
// Input: {"items": ["not-an-object", {"product": "Widget", ...}]}

$e->getErrors();
// [
//     'items.0' => [['rule' => 'array']],
// ]
```

## Nested Features

Child input classes support the full attribute set:

```php
use Solo\Input\Attributes\{Validate, Cast, PostProcess, Generator, Group};
use Solo\Input\Attributes\Source\FromContext;

#[Input]
final class OrderItemInput
{
    public function __construct(
        #[Generator(UuidGenerator::class)]
        public string $id,
        #[Validate('required|string|max:255')]
        public string $product,
        #[Validate('required|integer|min_value:1')]
        public int $quantity,
        #[Validate('required|numeric|min_value:0')]
        #[PostProcess(RoundCents::class)]
        public float $price,
        #[FromContext('tenantId')]
        public int $tenantId,
        #[Group('pricing')]
        public ?float $discount = null,
    ) {
    }
}
```

## Route and context in nested items

Route params are **NOT propagated to nested items**. They describe the outer resource (e.g. an order id), not each item. Merging them into item data would silently overwrite item-level fields whose name happened to match a route key (e.g. each item's `id` becoming the outer route's `id`).

`$context` IS propagated, so items can read it via `#[FromContext]` and item normalizers, casters and post-processors can read `ProcessContext::$context`.

```php
#[Input]
final class LineItemInput
{
    public function __construct(
        #[FromContext('tenantId')]
        public int $tenantId,
        #[Validate('required|integer|exists:products,id')]
        public int $productId,
        #[Validate('required|integer|min_value:1')]
        public int $quantity,
    ) {
    }
}

$dto = $factory->fromBody(
    OrderInput::class,
    $request,
    route:   ['orderId' => 7],          // not visible inside items
    context: ['tenantId' => 42],        // each item picks it up via #[FromContext]
);
```

## fromArray()

For testing or manual processing, use `fromArray()` to process raw data without a PSR-7 request:

```php
$item = $factory->fromArray(OrderItemInput::class, [
    'product' => 'Widget',
    'quantity' => '3',
    'price' => '9.99',
]);

$item->product;  // 'Widget'
$item->quantity; // 3 (int)
$item->price;    // 9.99 (float)
```

## Processing Pipeline

For fields with `#[Items]`, the processing order is:

1. Normalize present parent fields, then validate their raw values.
2. Resolve each item through normalization, raw validation and casting; collect all input errors.
3. If any node has an input error, throw the aggregate `ValidationException` before finalization.
4. Finalize children, then run the current node's post-processors and generators, then call its constructor.

`#[Items]` uses the child pipeline instead of a scalar/array caster. A parent post-processor receives completed item DTOs (including generated fields); its result is checked against the item class and is not revalidated. To prepare raw item data, use a normalizer.

## Configuration Validation

Invalid configurations throw `ConfigurationException` at build time:

| Error | Cause | Fix |
|-------|-------|-----|
| Class does not exist | `#[Items('NonExistent')]` | Check class name and imports |
| Must be marked #[Input] | `#[Items(SomeClass::class)]` (not an input class) | Mark the class `#[Input]` |
| Requires array type | `#[Items]` on a `string` field | Use `array` or `?array` type |
| Items with generator | Both `#[Items]` and `#[Generator]` on the same field | Remove one — they are mutually exclusive |

## Practical Examples

### E-commerce Order

```php
#[Input]
final class AddressInput
{
    public function __construct(
        #[Validate('required|string|max:255')]
        public string $street,
        #[Validate('required|string|max:100')]
        public string $city,
        #[Validate('required|string|size:2')]
        public string $country,
        #[Validate('required|string|max:20')]
        public string $zip,
    ) {
    }
}

#[Input]
final class OrderItemInput
{
    public function __construct(
        #[Validate('required|integer|exists:products,id')]
        public int $productId,
        #[Validate('required|integer|min_value:1|max_value:99')]
        public int $quantity,
    ) {
    }
}

#[Input]
final class CreateOrderInput
{
    public function __construct(
        #[Generator(UuidGenerator::class)]
        public string $id,
        #[Validate('array|min:1')]
        #[Items(OrderItemInput::class)]
        public array $items,
        #[Validate('array')]
        #[Items(AddressInput::class)]
        public array $addresses,
        #[Validate('string|max:500')]
        public ?string $notes = null,
    ) {
    }
}
```

### Survey with Questions

```php
#[Input]
final class AnswerInput
{
    public function __construct(
        #[Validate('required|integer|exists:questions,id')]
        public int $questionId,
        #[Validate('required|string|max:1000')]
        public string $value,
    ) {
    }
}

#[Input]
final class SubmitSurveyInput
{
    public function __construct(
        #[Validate('required|integer|exists:surveys,id')]
        public int $surveyId,
        #[Validate('array|min:1')]
        #[Items(AnswerInput::class)]
        public array $answers,
    ) {
    }
}
```
