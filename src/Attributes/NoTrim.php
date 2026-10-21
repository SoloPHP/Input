<?php

declare(strict_types=1);

namespace Solo\Input\Attributes;

use Attribute;

/**
 * Keep the string as sent: auto-trim (on by default, see `InputFactory`'s
 * `$autoTrim`) skips this property. For values whose leading or trailing
 * whitespace means something — code, Markdown, a password, a fixed-width
 * field.
 *
 * On a class, it applies to every property of the class and its subclasses.
 * Auto-trim never touches `#[FromContext]` values — they are the
 * application's.
 *
 * Example:
 * ```php
 * #[NoTrim]
 * #[Validate('required|string')]
 * public string $snippet,   // "  echo 1;\n" stays as it is
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY)]
final class NoTrim
{
}
