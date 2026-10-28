<?php

declare(strict_types=1);

namespace Solo\Input\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Declares validation rules for a property.
 *
 * The rules check a value. A field with no value — `null`, or a `""` its type
 * cannot hold, such as `?page=` for an `int` or a list — reaches the
 * validator as `null` and is checked by presence rules alone (`required`,
 * `filled`, `present`, `required_if`…): a nullable field then holds `null`,
 * one with a default keeps it, any other reports `required`. The type says
 * whether a field may be null, so it needs no `nullable` rule.
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
