<?php

declare(strict_types=1);

namespace Solo\Input\Attributes;

use Attribute;
use InvalidArgumentException;

/**
 * Read the property's date in a fixed `createFromFormat()` format.
 *
 * The class comes from the declared type: `DateTimeImmutable` and
 * `DateTimeInterface` (or an untyped / `mixed` property) yield a
 * `DateTimeImmutable`, `DateTime` yields a `DateTime`. Without this attribute
 * a date-typed property still parses any format `new DateTimeImmutable()`
 * understands, plus Unix timestamps.
 *
 * A format without time-of-day fields (`Y-m-d`) yields midnight; other
 * unparsed fields keep `createFromFormat()` semantics.
 *
 * Example:
 * ```php
 * #[DateFormat('Y-m-d')]
 * public DateTimeImmutable $birthday,
 *
 * #[DateFormat('Y-m-d H:i:s')]
 * public ?DateTime $publishedAt = null,
 * ```
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class DateFormat
{
    public function __construct(
        public string $format,
    ) {
        if ($format === '') {
            throw new InvalidArgumentException('#[DateFormat] format must be a non-empty string.');
        }
    }
}
