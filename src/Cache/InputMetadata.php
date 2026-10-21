<?php

declare(strict_types=1);

namespace Solo\Input\Cache;

use ReflectionClass;
use Solo\Input\Contracts\GeneratorInterface;

/**
 * Cached metadata for an input class
 *
 * @internal
 */
final readonly class InputMetadata
{
    /**
     * Every property name, in declaration order — the set `provided()`
     * records for a DTO the request supplied in full.
     *
     * @var array<string, true>
     */
    public array $names;

    /**
     * @param class-string                                    $className
     * @param array<string, PropertyMetadata>                 $properties
     * @param ReflectionClass<covariant object>               $reflection
     * @param array<string, class-string<GeneratorInterface>> $generated     `#[Generator]` property → generator
     * @param array<string, string>                           $postProcessed Post-processed property → handler
     * @param array<string, list<string>>                     $groups        `#[Group]` name → member property names
     */
    public function __construct(
        public string $className,
        public array $properties,
        public ReflectionClass $reflection,
        public array $generated,
        public array $postProcessed,
        public array $groups,
    ) {
        $this->names = array_fill_keys(array_keys($properties), true);
    }
}
