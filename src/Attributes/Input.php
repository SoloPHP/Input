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
 * `partial: true` marks the body of a partial update. An absent field still
 * reads as its default, but `Inputs::toArray()` leaves it out unless asked
 * for every field: the array is the change set, so a default never
 * overwrites what is stored. A subclass without a marker of its own keeps
 * the flag; one with `#[Input]` decides for itself. A null clears a nullable
 * field; one the client may leave out but not clear says so with `filled`.
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
 *
 * #[Input(partial: true)]
 * final readonly class UpdateProductInput
 * {
 *     public function __construct(
 *         #[Validate('filled|string|max:255')]
 *         public ?string $name = null,   // may be left out, not cleared
 *         #[Validate('string')]
 *         public ?string $note = null,   // null clears it
 *     ) {
 *     }
 * }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Input
{
    public function __construct(
        public bool $partial = false,
    ) {
    }
}
