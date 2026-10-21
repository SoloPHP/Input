<?php

declare(strict_types=1);

namespace Solo\Input;

/**
 * Context handed to normalizers, casters and post-processors.
 *
 * - `$config`  — per-attribute configuration from Normalize / Caster / PostProcess
 * - `$route`   — the route-params array passed to `fromBody()` / `fromQuery()` /
 *                `fromArray()`. Same array consulted by `#[FromRoute]`.
 * - `$context` — the generic application-context array passed alongside. Same
 *                array consulted by `#[FromContext]`.
 */
final readonly class ProcessContext
{
    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $route
     * @param array<string, mixed> $context
     */
    public function __construct(
        public array $config = [],
        public array $route = [],
        public array $context = [],
    ) {
    }
}
