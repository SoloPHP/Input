<?php

declare(strict_types=1);

namespace Solo\Input\Attributes;

use Attribute;

/**
 * Exclude the field from `Inputs::toArray()` output.
 *
 * The field is still read from input, validated, cast and processed normally —
 * it just won't appear in the exported array. Useful for sensitive fields
 * (passwords, tokens) that participate in the pipeline but must not leak out.
 *
 * Example:
 * ```php
 * #[Validate('required|string|min:8')]
 * #[Exclude]
 * public string $password,
 * ```
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Exclude
{
}
