<?php

declare(strict_types=1);

namespace Solo\Input\Attributes\Source;

use Attribute;
use InvalidArgumentException;

/**
 * Read the property from route parameters.
 *
 * Route params are the `$route` array passed to `fromBody()` / `fromQuery()` /
 * `fromArray()`. They typically come from the URL pattern (`/products/{id}`).
 *
 * `$key` is the route-param name. When `null`, the property name is used.
 * Route param keys are flat — dots in `$key` are part of the literal key name.
 *
 * Example:
 * ```php
 * #[FromRoute]                   // key = 'id', matches /products/{id}
 * #[Validate('required|integer|exists:products,id')]
 * public int $id,
 *
 * #[FromRoute('product_id')]
 * #[Validate('required|integer|exists:products,id')]
 * public int $productId,
 * ```
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class FromRoute
{
    public function __construct(
        public ?string $key = null,
    ) {
        if ($key !== null && $key === '') {
            throw new InvalidArgumentException('#[FromRoute] key must be a non-empty string or null.');
        }
    }
}
