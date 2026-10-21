<?php

declare(strict_types=1);

namespace Solo\Input\Attributes;

use Attribute;

/**
 * Mark a class as an input DTO. `InputFactory` builds it from input through
 * its constructor, and a field typed with it is a nested DTO.
 *
 * Every constructor parameter is a field, held by the public property of the
 * same name — usually promoted. A field with a default is optional; one
 * without is required — an absent field fails `required`. A subclass of an
 * input class is an input class too: its constructor may pass inherited
 * fields to `parent::__construct()`.
 *
 * Example:
 * ```php
 * #[Input]
 * final readonly class CreateProductInput
 * {
 *     public function __construct(
 *         #[Validate('required|string|max:255')]
 *         public string $name,
 *         #[Validate('integer|min_value:0')]
 *         public int $stock = 0,
 *     ) {
 *     }
 * }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Input
{
}
