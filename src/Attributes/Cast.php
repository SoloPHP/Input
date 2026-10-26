<?php

declare(strict_types=1);

namespace Solo\Input\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Explicit cast for the property value: `int`, `float`, `bool`, `string`,
 * `array` — or `json`, which encodes a structured input (an object or list)
 * into a `string` property instead of rejecting it, and `decimal`, which
 * writes a number into a `string` property in plain decimal notation
 * (`0.00001` and `'1e-5'` as `'0.00001'`).
 *
 * Without this attribute, casting is driven by the property's declared type
 * (e.g. `public int $age` is cast as int), so `#[Cast]` is only needed where
 * the type does not say it — a union or `mixed` property.
 *
 * Dates are cast by their type too; use `#[DateFormat]` for a fixed format.
 * For custom logic use `#[Caster(MyCaster::class)]` — `#[Cast]` does NOT
 * accept class names and throws `ConfigurationException` at metadata build
 * time if given one.
 *
 * Example:
 * ```php
 * #[Cast('float')]
 * public int|float $amount,
 * ```
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Cast
{
    public function __construct(
        public string $type,
    ) {
        if ($type === '') {
            throw new InvalidArgumentException('#[Cast] type must be a non-empty string.');
        }
    }
}
