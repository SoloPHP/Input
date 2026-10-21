<?php

declare(strict_types=1);

namespace Solo\Input\Attributes\Source;

use Attribute;
use InvalidArgumentException;

/**
 * Read the property from the generic application context.
 *
 * `$context` is the array passed to `fromBody()` / `fromQuery()` /
 * `fromArray()`. Use this for application-supplied values that aren't
 * part of the HTTP request itself — authenticated user id, tenant info,
 * feature flags, etc.
 *
 * `$key` is required. Keys are flat — dots are part of the literal key name.
 *
 * Example:
 * ```php
 * #[FromContext('authenticatedUserId')]
 * #[Validate('required|integer')]
 * public int $createdBy,
 * ```
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class FromContext
{
    public function __construct(
        public string $key,
    ) {
        if ($key === '') {
            throw new InvalidArgumentException('#[FromContext] key must be a non-empty string.');
        }
    }
}
