<?php

declare(strict_types=1);

namespace Solo\Input\Pipeline;

use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * Precomputed strict compatibility check for a property's declared type.
 * Union decoding also uses its ordered lossless scalar casts.
 *
 * @internal
 */
final readonly class TypeGuard
{
    /**
     * The scalar types a value of none of the members may be converted to,
     * in PHP's own coercion order.
     */
    private const array CASTS = ['int', 'float', 'string', 'bool'];

    /**
     * @param list<list<string>> $alternatives Union members, each an intersection
     *                                         of type names (built-in names lower-cased).
     * @param list<string>       $casts        The scalar members a value of none of the types is
     *                                         converted to without loss, in coercion order; a
     *                                         literal `true` / `false` is reached through bool.
     */
    private function __construct(
        private bool $allowsNull,
        private array $alternatives,
        public array $casts,
    ) {
    }

    /**
     * Null when the type accepts anything (untyped or `mixed`).
     *
     * @param class-string $scope Declaring class, for self/parent types.
     */
    public static function fromType(?ReflectionType $type, string $scope): ?self
    {
        if ($type === null || ($type instanceof ReflectionNamedType && $type->getName() === 'mixed')) {
            return null;
        }

        $alternatives = [];
        // Single-type members — built-ins lower-cased, class names as written;
        // intersections are left out.
        $members = [];
        foreach ($type instanceof ReflectionUnionType ? $type->getTypes() : [$type] as $member) {
            $intersection = [];
            foreach ($member instanceof ReflectionIntersectionType ? $member->getTypes() : [$member] as $named) {
                if ($named instanceof ReflectionNamedType) {
                    $name = $named->isBuiltin() ? strtolower($named->getName()) : $named->getName();
                    $intersection[] = match ($name) {
                        'self' => $scope,
                        'parent' => get_parent_class($scope) ?: $name,
                        default => $name,
                    };
                }
            }
            $alternatives[] = $intersection;
            if (count($intersection) === 1) {
                $members[] = $intersection[0];
            }
        }

        $casts = array_values(array_intersect(self::CASTS, $members));
        if (!in_array('bool', $casts, true) && array_intersect(['true', 'false'], $members) !== []) {
            $casts[] = 'bool';
        }

        return new self($type->allowsNull(), $alternatives, $casts);
    }

    public function accepts(mixed $value): bool
    {
        if ($value === null) {
            return $this->allowsNull;
        }

        foreach ($this->alternatives as $intersection) {
            foreach ($intersection as $name) {
                if (!self::matches($name, $value)) {
                    continue 2;
                }
            }
            return true;
        }

        return false;
    }

    private static function matches(string $name, mixed $value): bool
    {
        return match ($name) {
            'string' => is_string($value),
            'int' => is_int($value),
            'float' => is_float($value) || is_int($value),
            'bool' => is_bool($value),
            'true' => $value === true,
            'false' => $value === false,
            'array' => is_array($value),
            'iterable' => is_iterable($value),
            'object' => is_object($value),
            'null' => false,
            default => $value instanceof $name,
        };
    }
}
