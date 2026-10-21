<?php

declare(strict_types=1);

namespace Solo\Input\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Declares validation rules for a property.
 *
 * Example:
 * ```php
 * #[Validate('required|string|max:100')]
 * public string $name,
 * ```
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Validate
{
    public function __construct(
        public string $rules,
    ) {
        if ($rules === '') {
            throw new InvalidArgumentException(
                '#[Validate] rules must be a non-empty string. ' .
                'Omit the attribute entirely if no validation is needed.'
            );
        }
    }
}
