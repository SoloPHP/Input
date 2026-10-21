<?php

declare(strict_types=1);

namespace Solo\Input\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Assign the field to a named group for `Inputs::group()` extraction.
 *
 * `mapTo` optionally remaps the field name when the group output array is built.
 *
 * Example:
 * ```php
 * #[Validate('integer')]
 * #[Group('criteria', mapTo: 'category_id')]
 * public int $category,
 *
 * #[Validate('numeric')]
 * #[Group('criteria', mapTo: 'price >=')]
 * public ?string $min_price = null,
 * ```
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Group
{
    public function __construct(
        public string $name,
        public ?string $mapTo = null,
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('#[Group] name must be a non-empty string.');
        }
        if ($mapTo !== null && $mapTo === '') {
            throw new InvalidArgumentException('#[Group] mapTo must be a non-empty string or null.');
        }
    }
}
