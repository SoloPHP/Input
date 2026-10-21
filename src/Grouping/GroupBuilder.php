<?php

declare(strict_types=1);

namespace Solo\Input\Grouping;

use Solo\Input\Cache\InputMetadata;

/**
 * Assembles a group from current DTO values using cached metadata.
 *
 * @internal Use `Inputs::group()`.
 */
final class GroupBuilder
{
    /**
     * @return array<array-key, mixed>
     * @throws \LogicException When two fields produce the same key.
     */
    public static function build(object $input, InputMetadata $metadata, string $groupName): array
    {
        $result = [];

        foreach ($metadata->groups[$groupName] ?? [] as $name) {
            $property = $metadata->properties[$name];
            if (!$property->reflection->isInitialized($input)) {
                continue;
            }

            $value = $property->reflection->getValue($input);

            // Skip empty arrays and nulls — they convey "no criteria for this slot".
            if ($value === [] || $value === null) {
                continue;
            }

            $entries = (is_array($value) && !array_is_list($value))
                ? $value
                : [($property->groupMapTo ?? $name) => $value];

            foreach ($entries as $key => $v) {
                if (array_key_exists($key, $result)) {
                    throw new \LogicException(
                        "Duplicate key '$key' in group '$groupName' from field '{$name}'"
                    );
                }
                $result[$key] = $v;
            }
        }

        return $result;
    }
}
