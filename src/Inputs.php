<?php

declare(strict_types=1);

namespace Solo\Input;

use Solo\Input\Cache\MetadataBuilder;
use Solo\Input\Cache\ReflectionCache;
use Solo\Input\Grouping\GroupBuilder;
use Solo\Input\Pipeline\ProvidedFields;

/**
 * Reads input DTOs — `#[Input]` classes — the way `InputFactory` sees them:
 * as an array, by group, and by what the request supplied.
 *
 * A DTO holds its fields and nothing else; these readers live beside it.
 * Each one takes an input DTO: another object fails with
 * `ConfigurationException`, as it is not an input class.
 *
 * Example:
 * ```php
 * $input = $inputs->fromBody(UpdateProductInput::class, $request);
 *
 * Inputs::toArray($input);                     // every field but #[Exclude] ones —
 *                                              // the change set for #[Input(partial: true)]
 * Inputs::toArray($input, onlyProvided: true); // the change set of a PATCH
 * Inputs::group($input, 'criteria');           // the #[Group('criteria')] fields
 * Inputs::isProvided($input, 'note');          // sent, even as null?
 * ```
 */
final class Inputs
{
    /**
     * The fields as an array in declaration order, `#[Exclude]` ones left
     * out. A nested input DTO — in a field or a list — becomes an array too.
     *
     * With `$onlyProvided`, only the fields the request supplied
     * (`provided()`), nested DTOs and `#[Items]` included — the change set of
     * a PATCH. A nested DTO the factory did not build — one the application
     * passed in context — is a value supplied as a whole.
     *
     * Without it, the class decides: `#[Input(partial: true)]` gives the
     * change set — nothing, for a DTO the factory did not build — and any
     * other class every field. Nested DTOs follow the call they are in.
     *
     * @return array<string, mixed>
     */
    public static function toArray(object $input, ?bool $onlyProvided = null): array
    {
        $result = [];
        $metadata = ReflectionCache::of($input::class);
        $onlyProvided ??= $metadata->partial;
        $provided = $onlyProvided ? ProvidedFields::of($input) : [];

        foreach ($metadata->properties as $name => $property) {
            if ($property->exclude || !$property->reflection->isInitialized($input)) {
                continue;
            }
            if ($onlyProvided && !isset($provided[$name])) {
                continue;
            }

            $value = $property->reflection->getValue($input);
            $onlyProvidedChildren = $onlyProvided && $property->buildsChildren;

            if (is_object($value) && MetadataBuilder::isInputClass($value::class)) {
                $value = self::toArray($value, $onlyProvidedChildren && ProvidedFields::knows($value));
            } elseif (is_array($value)) {
                $exported = [];
                foreach ($value as $key => $item) {
                    $exported[$key] = is_object($item) && MetadataBuilder::isInputClass($item::class)
                        ? self::toArray($item, $onlyProvidedChildren && ProvidedFields::knows($item))
                        : $item;
                }
                $value = $exported;
            }

            $result[$name] = $value;
        }

        return $result;
    }

    /**
     * The fields of a `#[Group]` as one flat array, in declaration order.
     *
     * - Associative array fields: contents are merged by their own keys.
     * - Other values — scalars, sequential arrays, objects: added under the field name (or `mapTo`).
     * - Empty arrays and nulls are skipped — they carry no group info.
     *
     * @throws \LogicException When two fields produce the same key.
     * @return array<array-key, mixed>
     */
    public static function group(object $input, string $name): array
    {
        return GroupBuilder::build($input, ReflectionCache::of($input::class), $name);
    }

    /**
     * Names of the fields the request supplied, in declaration order: every
     * field whose key was present in its source (body, query, route or
     * context) — an explicit `null` or `""` included — and the generated
     * ones. A field left at its default because the key was absent is not
     * listed. Empty for a DTO `InputFactory` did not build — one created with
     * `new`, a clone or an unserialized copy included.
     *
     * @return list<string>
     */
    public static function provided(object $input): array
    {
        ReflectionCache::of($input::class);

        return array_keys(ProvidedFields::of($input));
    }

    /**
     * Whether the request supplied the field — see `provided()`. Unlike
     * reading the field, a default does not count: with `public ?string $note
     * = null` absent from the input, `$input->note` is `null` and
     * `isProvided($input, 'note')` is `false`.
     */
    public static function isProvided(object $input, string $name): bool
    {
        ReflectionCache::of($input::class);

        return ProvidedFields::has($input, $name);
    }

    /**
     * Clear the metadata registry shared by `InputFactory` and these readers.
     *
     * Primarily a test helper for isolating cases within a single PHP process —
     * metadata is per-class and immutable, so production code never needs to
     * call this.
     *
     * @param class-string|null $className Clear cache for specific class only, or all if null
     */
    public static function clearCache(?string $className = null): void
    {
        ReflectionCache::shared()->clear($className);
    }
}
