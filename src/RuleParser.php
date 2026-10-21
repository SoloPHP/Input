<?php

declare(strict_types=1);

namespace Solo\Input;

/**
 * Single source of truth for validation rule-string split semantics.
 *
 * Shared by metadata building (`MetadataBuilder`) and schema export
 * (`SchemaBuilder`) so both interpret a `#[Validate]` string identically:
 *
 * - the raw string is split on `|`;
 * - each rule's name is the part before the first `:`;
 * - the remainder, when present, is split on `,` into arguments;
 * - a rule with no `:` has an empty argument list;
 * - empty segments (e.g. from a trailing `|`) are skipped.
 *
 * Splitting on `|` first avoids false matches inside value lists
 * (e.g. `in:nullable,active` must NOT be read as a `nullable` rule).
 *
 * @phpstan-type NormalizedRule array{name: string, args: list<string>}
 * @internal
 */
final class RuleParser
{
    /**
     * Parse a pipe-separated rule string into a normalized list.
     *
     * @return list<NormalizedRule>
     */
    public static function parse(?string $rules): array
    {
        if ($rules === null || $rules === '') {
            return [];
        }

        $parsed = [];
        foreach (explode('|', $rules) as $rule) {
            $rule = trim($rule);
            if ($rule === '') {
                continue;
            }

            $colonAt = strpos($rule, ':');
            if ($colonAt === false) {
                $parsed[] = ['name' => $rule, 'args' => []];
                continue;
            }

            $name = substr($rule, 0, $colonAt);
            $argsPart = substr($rule, $colonAt + 1);
            $args = $argsPart === '' ? [] : explode(',', $argsPart);

            $parsed[] = ['name' => $name, 'args' => $args];
        }

        return $parsed;
    }

    /**
     * Whether `$rules` contains a rule whose name equals `$ruleName`.
     */
    public static function has(?string $rules, string $ruleName): bool
    {
        foreach (self::parse($rules) as $rule) {
            if ($rule['name'] === $ruleName) {
                return true;
            }
        }

        return false;
    }
}
