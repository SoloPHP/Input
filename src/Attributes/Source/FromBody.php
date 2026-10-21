<?php

declare(strict_types=1);

namespace Solo\Input\Attributes\Source;

use Attribute;
use InvalidArgumentException;

/**
 * Read the property from the request body using a dot-path lookup.
 *
 * Use this when the client's JSON puts the value under a nested key and you
 * don't want to flatten it in the controller. For body keys that match the
 * property name 1:1, omit the attribute entirely — body is the default source
 * for `fromBody()` / `fromArray()`.
 *
 * `$path` is required and dot-separated. Each segment is a literal key name.
 * Numeric indices are supported via integer-looking segments (`items.0.sku`).
 * If any segment is missing or an intermediate value isn't an array, the
 * lookup behaves like a missing key (the property gets its default or
 * triggers `required` validation).
 *
 * Example:
 * ```php
 * #[FromBody('customer.id')]
 * #[Validate('required|integer|exists:users,id')]
 * public int $customerId,
 *
 * #[FromBody('shipping.address.zip')]
 * public string $shippingZip,
 * ```
 *
 * `#[FromBody]` is the body counterpart of `#[FromRoute]` / `#[FromContext]`;
 * only one source attribute is permitted per property.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class FromBody
{
    public function __construct(
        public string $path,
    ) {
        if ($path === '') {
            throw new InvalidArgumentException('#[FromBody] path must be a non-empty string.');
        }
        if ($path[0] === '.' || str_ends_with($path, '.') || str_contains($path, '..')) {
            throw new InvalidArgumentException(sprintf(
                "#[FromBody] path '%s' is malformed: must not start or end with '.' or contain empty segments ('..').",
                $path
            ));
        }
    }
}
