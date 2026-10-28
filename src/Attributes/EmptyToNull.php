<?php

declare(strict_types=1);

namespace Solo\Input\Attributes;

use Attribute;

/**
 * A blank string — `""`, or only whitespace — is no value: it becomes `null`
 * after normalization and before validation. Like any field with no value,
 * it is checked by presence rules alone (`required`, `filled`,
 * `required_if`…), then holds `null` when nullable, keeps its default when
 * it has one, and reports `required` otherwise. A filled string passes
 * through as it is.
 *
 * Without it a blank string is kept for string properties, so one column
 * ends up with two shapes of "not filled in" (`''` and `NULL`).
 *
 * On a class, it applies to every property of the class and its subclasses
 * that the client sends — not to `#[FromContext]` or generated ones. Only
 * strings are blank here — `[]` is still an array.
 *
 * Example:
 * ```php
 * #[EmptyToNull]
 * #[Validate('date:Y-m-d')]
 * public ?string $endDate = null,   // "" → null
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
final class EmptyToNull
{
}
