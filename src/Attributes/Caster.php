<?php

declare(strict_types=1);

namespace Solo\Input\Attributes;

use Attribute;

/**
 * Custom CasterInterface class for the property value.
 *
 * Use this when built-in casts (see `#[Cast]`) are not enough — for example,
 * to wrap a primitive into a value object.
 *
 * Example:
 * ```php
 * #[Caster(MoneyCaster::class)]
 * public Money $price,
 * ```
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Caster
{
    /**
     * @param class-string         $class Must implement CasterInterface.
     * @param array<string, mixed> $config
     */
    public function __construct(
        public string $class,
        public array $config = [],
    ) {
    }
}
