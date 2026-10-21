<?php

declare(strict_types=1);

namespace Solo\Input\Pipeline;

use Solo\Input\Cache\InputMetadata;
use Solo\Input\Exceptions\ValidationException;

/**
 * Combines validation errors and maps them to input paths in declaration order.
 *
 * @phpstan-import-type ValidationErrors from ValidationException
 * @internal Errors are exposed through `ValidationException::getErrors()`.
 */
final class ErrorMapper
{
    /**
     * Accumulate errors in place, without copying the growing collection,
     * each under `$prefix` + its key — callers include the path separator.
     *
     * @param ValidationErrors        $errors
     * @param array<array-key, mixed> $more
     */
    public static function append(array &$errors, array $more, string $prefix = ''): void
    {
        foreach ($more as $field => $messages) {
            /** @var list<array{rule: string, params?: string[]}> $messages */
            $key = $prefix . $field;
            $errors[$key] = array_merge($errors[$key] ?? [], $messages);
        }
    }

    /**
     * Errors keyed by where the client put each value — the input key
     * (`display_name`, `customer.id`, a route parameter) rather than the PHP
     * property name the validator and handlers use — in property declaration
     * order, each nested-DTO or items property followed by its children's
     * errors: the order a form presents its fields in. Called only for a node
     * with errors.
     *
     * @param ValidationErrors                $errors      Keyed by property name (or anything else).
     * @param array<string, ValidationErrors> $childErrors Already keyed by input path.
     * @return ValidationErrors
     */
    public static function order(InputMetadata $metadata, array $errors, array $childErrors): array
    {
        // Lists sharing an input key — two properties reading it, or a flat
        // #[FromBody('a.b')] next to a nested `a` — are merged, never dropped.
        $ordered = [];
        foreach ($metadata->properties as $name => $property) {
            if (isset($errors[$name])) {
                self::append($ordered, [$property->sourceKey => $errors[$name]]);
                unset($errors[$name]);
            }
            self::append($ordered, $childErrors[$name] ?? []);
        }

        // Keys reported for something other than a property.
        self::append($ordered, $errors);

        return $ordered;
    }
}
