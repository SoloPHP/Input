<?php

declare(strict_types=1);

namespace Solo\Input\Attributes;

use Attribute;

/**
 * Marks the field as a list of nested input DTOs.
 *
 * Each element in the input array is processed through the given `#[Input]` class.
 * The property must be typed `array` or `?array`.
 *
 * Mutually exclusive with `#[Cast]` and `#[Caster]` (items bypass automatic
 * casting — each element runs through its own DTO pipeline).
 *
 * Example:
 * ```php
 * #[Validate('nullable|array')]
 * #[Items(OrderLineInput::class)]
 * public ?array $items = null,
 * ```
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Items
{
    /**
     * @param class-string $class
     */
    public function __construct(
        public string $class,
    ) {
    }
}
