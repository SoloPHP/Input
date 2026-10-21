<?php

declare(strict_types=1);

namespace Solo\Input\Pipeline;

use Solo\Input\Cache\InputMetadata;
use WeakMap;

/**
 * What the request supplied for each DTO `InputFactory` built — kept
 * beside the instance rather than in it, so `(array)`, `serialize()` and
 * property normalizers see only the DTO's own fields. An entry lives as long
 * as its DTO.
 *
 * Each entry is a set of field names in declaration order, including
 * explicitly supplied nulls and generated values. A DTO supplied in full
 * shares its class's set rather than holding a copy, and DTOs of one class
 * supplied with the same fields — the items of a list — share one set too.
 *
 * @internal Read it through `Inputs::provided()`.
 */
final class ProvidedFields
{
    /** @var WeakMap<object, array<string, true>>|null */
    private static ?WeakMap $fields = null;

    /** @var array<string, array<string, true>> The last partial set recorded per class. */
    private static array $partial = [];

    /**
     * @param array<string, mixed> $values What the constructor received, keyed by field name.
     */
    public static function record(object $dto, InputMetadata $metadata, array $values): void
    {
        if (count($values) === count($metadata->names)) {
            $provided = $metadata->names;
        } else {
            $provided = self::$partial[$metadata->className] ?? [];
            if (count($provided) !== count($values) || array_diff_key($values, $provided) !== []) {
                // Declaration order, whatever the order of $values: finalize()
                // adds generated values last.
                $provided = self::$partial[$metadata->className] = array_intersect_key($metadata->names, $values);
            }
        }
        self::$fields ??= new WeakMap();
        self::$fields[$dto] = $provided;
    }

    /**
     * The names the request supplied, in declaration order.
     *
     * @return array<string, true>
     */
    public static function of(object $dto): array
    {
        return self::$fields[$dto] ?? [];
    }

    /**
     * Whether the request supplied the property.
     */
    public static function has(object $dto, string $name): bool
    {
        return isset(self::$fields[$dto][$name]);
    }

    /**
     * Whether `InputFactory` built the DTO — and so recorded what the
     * request supplied for it, possibly nothing.
     */
    public static function knows(object $dto): bool
    {
        return isset(self::$fields[$dto]);
    }
}
