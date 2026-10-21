<?php

declare(strict_types=1);

namespace Solo\Input\Contracts;

/**
 * Generate a value after the entire input tree is valid.
 * Children and local post-processors finish before this node's generators.
 * Validation exceptions during generation are contract errors.
 *
 * Example:
 * ```php
 * final class UuidGenerator implements GeneratorInterface
 * {
 *     public function generate(array $options = []): string
 *     {
 *         return Uuid::uuid4()->toString();
 *     }
 * }
 * ```
 */
interface GeneratorInterface
{
    /**
     * Generate a value for a field.
     *
     * @param array<string, mixed> $options Options passed from #[Generator]
     * @return mixed The generated value
     */
    public function generate(array $options = []): mixed;
}
