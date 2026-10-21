<?php

declare(strict_types=1);

namespace Solo\Input\Attributes;

use Attribute;
use Solo\Input\Contracts\GeneratorInterface;

/**
 * Generate the property value instead of reading it from any source.
 *
 * Presence of this attribute means the property is NOT looked up in body /
 * query / route / etc. — the generator class produces the value (UUID,
 * sequential id, timestamp, …).
 *
 * Mutually exclusive with every input attribute — the sources, `#[Validate]`,
 * `#[Cast]`, `#[Caster]`, `#[DateFormat]`, `#[EmptyToNull]`, `#[NoTrim]`,
 * `#[Normalize]`, `#[PostProcess]`, `#[Items]` — which would have nothing to
 * act on and trigger `ConfigurationException`.
 *
 * `options` are passed to `GeneratorInterface::generate()`.
 *
 * Example:
 * ```php
 * #[Generator(UuidGenerator::class)]
 * public string $id,
 *
 * #[Generator(IntIdGenerator::class, options: ['table' => 'users'])]
 * public int $id,
 * ```
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Generator
{
    /**
     * @param class-string<GeneratorInterface> $class
     * @param array<string, mixed>             $options
     */
    public function __construct(
        public string $class,
        public array $options = [],
    ) {
    }
}
