<?php

declare(strict_types=1);

namespace Solo\Input\Cache;

/**
 * Caches immutable metadata and guards recursive loading of input classes.
 *
 * The process-wide registry — `shared()` — serves both `InputFactory` and
 * the `Inputs` readers. MetadataBuilder owns construction and validation.
 *
 * @internal
 */
final class ReflectionCache
{
    private static ?self $shared = null;

    /** @var array<class-string, InputMetadata> */
    private array $cache = [];

    /** @var array<class-string, true> Classes currently being built. */
    private array $building = [];

    private readonly MetadataBuilder $builder;

    public function __construct()
    {
        $this->builder = new MetadataBuilder($this->loadChild(...));
    }

    public static function shared(): self
    {
        return self::$shared ??= new self();
    }

    /**
     * @param class-string $className
     */
    public function get(string $className): InputMetadata
    {
        return $this->cache[$className] ??= $this->build($className);
    }

    /**
     * The shared registry's metadata of a class in one call — the path the
     * `Inputs` readers take on every call.
     *
     * @param class-string $className
     */
    public static function of(string $className): InputMetadata
    {
        $shared = self::$shared ??= new self();

        return $shared->cache[$className] ??= $shared->build($className);
    }

    /**
     * Forget built metadata — for one class, or all when null.
     */
    public function clear(?string $className = null): void
    {
        if ($className === null) {
            $this->cache = [];
            return;
        }

        unset($this->cache[$className]);
    }

    /** @param class-string $className */
    private function build(string $className): InputMetadata
    {
        $this->building[$className] = true;

        try {
            return $this->builder->build($className);
        } finally {
            unset($this->building[$className]);
        }
    }

    /** @param class-string $className */
    private function loadChild(string $className): void
    {
        if (!isset($this->building[$className])) {
            $this->get($className);
        }
    }
}
