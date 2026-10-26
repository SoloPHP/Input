<?php

declare(strict_types=1);

namespace Solo\Input\Casters;

use BackedEnum;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use ReflectionEnum;
use Solo\Input\Pipeline\TypeGuard;

/**
 * Handles built-in type casting
 *
 * @internal
 */
final class BuiltInCaster
{
    /**
     * Built-in cast types: the `#[Cast]` vocabulary and the type-driven casts
     * of `int` / `float` / `bool` / `string` / `array` properties. `json`
     * (explicit only) encodes a structured value into a string property, and
     * `decimal` (explicit only) writes a number into one in plain notation.
     */
    public const array CAST_TYPES = ['int', 'float', 'bool', 'string', 'array', 'json', 'decimal'];

    /**
     * What cast() returns for `""` by type — kept as data, so a property's
     * `blank` costs no call while its metadata is built.
     */
    public const array BLANK = [
        'int'      => null,
        'float'    => null,
        'bool'     => null,
        'string'   => '',
        'array'    => [],
        'json'     => '',
        'decimal'  => null,
        'iterable' => [],
    ];

    /**
     * Parser warnings for an impossible date or time it rolled over into a
     * real one (2024-02-31 → 2024-03-02, 23:61 → 00:01).
     */
    private const array ROLLOVER_WARNINGS = ['The parsed date was invalid', 'The parsed time was invalid'];

    /**
     * Memoised "does this format parse no time-of-day field" lookups —
     * facts about code, shared by every instance (the metadata registry and
     * each handler have one).
     *
     * @var array<string, bool>
     */
    private static array $midnightFormats = [];

    /**
     * Memoised "is this enum int-backed" lookups.
     *
     * @var array<class-string<BackedEnum>, bool>
     */
    private static array $enumIntBacked = [];

    public static function isBuiltIn(string $type): bool
    {
        return in_array($type, self::CAST_TYPES, true);
    }

    /**
     * Convert `$value` to `$type` without losing information — or return null
     * when it cannot be: `'12abc'` is no int, `['5']` no float, `true` no
     * number, `'maybe'` no bool. `""` reads as `BLANK` says: null for int /
     * float / bool / decimal — no value to the client, no fit for the
     * application — a string keeps it, an array reads it as `[]`.
     */
    public function cast(string $type, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'int' => $this->toInt($value),
            'float' => $this->toFloat($value),
            'bool' => $this->toBool($value),
            'string' => $this->toString($value),
            'array' => $this->toArray($value),
            'json' => $this->toJson($value),
            'decimal' => $this->toDecimal($value),
            // Type-driven only (no #[Cast]): an iterable already fits — a
            // generator or cursor is kept, not consumed — the rest is read
            // like an array.
            'iterable' => is_iterable($value) ? $value : $this->toArray($value),
            default => throw new InvalidArgumentException("Unknown cast type: {$type}"),
        };
    }

    /**
     * A value for a type cast member by member — a union property or a
     * standalone `true` / `false`. A value of a member type stays as it is;
     * any other one becomes the first of the
     * guard's `casts` it converts to without loss — PHP's coercion order,
     * int, float, string, bool — and that the type then accepts (`"0"` is a
     * bool, but no `true`). Null when none.
     */
    public function castToMembers(TypeGuard $guard, mixed $value): mixed
    {
        if ($guard->accepts($value)) {
            return $value;
        }

        foreach ($guard->casts as $type) {
            $cast = $this->cast($type, $value);
            if ($cast !== null && $guard->accepts($cast)) {
                return $cast;
            }
        }

        return null;
    }

    /**
     * Build a `$class` date from a string (in `$format` when given), a Unix
     * timestamp or another DateTime object. Null when the value cannot be
     * read as a date, `""` included.
     *
     * Without a format, a string of digits is a date when it spells one
     * (`20240115`) and a Unix timestamp when the parser rejects it
     * (`1700000000`); digits the parser takes for a time of day (`2024` →
     * 20:24 today) are not a date. Neither is an impossible date or time
     * (`2024-02-31`, `23:61`), which PHP would roll over.
     *
     * A format without time-of-day fields yields midnight: `createFromFormat()`
     * would otherwise copy the current time into the unparsed fields. With a
     * format, a number is read in it like a string (`20240115` for `Ymd`).
     *
     * @param class-string<DateTime|DateTimeImmutable> $class
     */
    public function castDate(string $class, ?string $format, mixed $value): DateTime|DateTimeImmutable|null
    {
        if ($value instanceof $class) {
            /** @var DateTime|DateTimeImmutable $value */
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return $class::createFromInterface($value);
        }

        if (is_int($value)) {
            if ($format === null) {
                return (new $class())->setTimestamp($value);
            }
            // The format says how to read the value — a number included.
            $value = (string) $value;
        }

        if (!is_string($value) || $value === '') {
            return null;
        }

        if ($format !== null) {
            $parsed = $class::createFromFormat($format, $value);
            if ($parsed === false || self::rolledOver($class)) {
                return null;
            }

            return (self::$midnightFormats[$format] ??= self::parsesNoTime($format))
                ? $parsed->setTime(0, 0)
                : $parsed;
        }

        $digits = preg_match('/^-?\d+$/', $value) === 1;

        try {
            $date = new $class($value);
        } catch (\Exception) {
            // The DateTime parser rejects a bare Unix timestamp ('1700000000'),
            // which is how query strings and form fields deliver one.
            return $digits ? (new $class())->setTimestamp((int) $value) : null;
        }

        if (self::rolledOver($class)) {
            return null;
        }

        return $digits && date_parse($value)['year'] === false ? null : $date;
    }

    /**
     * Resolve a BackedEnum case from its backing value. An int-backed enum
     * reads the value like an int property — integer strings (query strings
     * carry no ints) and whole floats (`2.0` from a JSON encoder) too.
     *
     * @template TEnum of BackedEnum
     * @param class-string<TEnum> $enumClass
     * @return TEnum|null Null when the value matches no case.
     */
    public function castEnum(string $enumClass, mixed $value): ?BackedEnum
    {
        if ($value instanceof $enumClass) {
            return $value;
        }

        $intBacked = self::$enumIntBacked[$enumClass]
            ??= (string) (new ReflectionEnum($enumClass))->getBackingType() === 'int';

        if ($intBacked) {
            $int = $this->toInt($value);
            return $int === null ? null : $enumClass::tryFrom($int);
        }

        return is_string($value) || is_int($value) ? $enumClass::tryFrom((string) $value) : null;
    }

    private function toInt(mixed $value): ?int
    {
        return match (true) {
            is_int($value) => $value,
            // Whole floats only, inside the range a float can hold exactly.
            is_float($value) => $value === floor($value) && abs($value) < 9.0E15 ? (int) $value : null,
            is_string($value) => ($int = filter_var($value, FILTER_VALIDATE_INT)) === false ? null : $int,
            default => null,
        };
    }

    private function toFloat(mixed $value): ?float
    {
        $float = match (true) {
            is_float($value), is_int($value) => (float) $value,
            is_string($value) && is_numeric($value) => (float) $value,
            default => null,
        };

        // "1e400" overflows to INF — no number a float property can hold.
        return $float !== null && is_finite($float) ? $float : null;
    }

    private function toBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === 1) {
            return $value === 1;
        }
        if (!is_string($value)) {
            return null;
        }

        return match (strtolower($value)) {
            'true', '1', 'yes', 'on' => true,
            'false', '0', 'no', 'off' => false,
            default => null,
        };
    }

    private function toString(mixed $value): ?string
    {
        return match (true) {
            is_string($value) => $value,
            is_float($value) => is_finite($value) ? (string) $value : null,
            is_int($value), $value instanceof \Stringable => (string) $value,
            default => null,
        };
    }

    /**
     * A number in plain decimal notation — an optional `-`, digits, and a
     * fraction only when it is not zero: what a DECIMAL column, bcmath or a
     * decimal library takes, and what `(string) 0.00001`, `'1.0E-5'`, is not.
     *
     * An int or a numeric string keeps every digit it has; a float gives its
     * shortest round-trip digits, so `0.1` stays `'0.1'`. The notation changes,
     * never the value: `'+5'`, `'005'` and `'5.0'` all read as `'5'`, and
     * `'-0'` as `'0'`. A number a float overflows to infinity, or rounds to
     * zero although it is not zero, is none.
     */
    private function toDecimal(mixed $value): ?string
    {
        return match (true) {
            is_int($value) => (string) $value,
            // `%H` at precision -1 writes the shortest digits that read back as
            // the same float, with a `.` whatever the locale or the ini settings.
            is_float($value) => is_finite($value) ? self::plainDecimal(sprintf('%.*H', -1, $value)) : null,
            is_string($value), $value instanceof \Stringable => self::plainDecimal((string) $value),
            default => null,
        };
    }

    /**
     * A numeric string — blanks around, a sign, digits with an optional
     * fraction, an optional exponent — rewritten without the exponent by
     * moving the point along its digits, so none of them is lost to a float.
     * The quantifiers are possessive: a run of blanks split between the two
     * `\s*` again and again would cost quadratic time.
     */
    private static function plainDecimal(string $number): ?string
    {
        if (preg_match('/\A\s*+([+-]?)(\d*+)(?:\.(\d*+))?(?:[eE]([+-]?\d++))?\s*+\z/', $number, $parts) !== 1) {
            return null;
        }
        $digits = $parts[2] . ($parts[3] ?? '');
        if ($digits === '') {
            return null;
        }
        if (ltrim($digits, '0') === '') {
            return '0';
        }
        // A float's range, not a column's precision: past it — infinity, or
        // zero for a number that is not — there is no number. That bounds how
        // far the point moves, so a short input cannot expand without limit
        // ('1e-99999999' would be a hundred million characters).
        $float = (float) $number;
        if ($float === 0.0 || !is_finite($float)) {
            return null;
        }

        $point = strlen($parts[2]) + (int) ($parts[4] ?? 0);
        // Zeros before the digits for a point left of them, after them for one right of them.
        $digits = str_repeat('0', max(0, -$point)) . str_pad($digits, $point, '0');
        $point = max(0, $point);
        $integer = ltrim(substr($digits, 0, $point), '0');
        $fraction = rtrim(substr($digits, $point), '0');

        return ($parts[1] === '-' ? '-' : '')
            . ($integer === '' ? '0' : $integer)
            . ($fraction === '' ? '' : '.' . $fraction);
    }

    private function toJson(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        $json = json_encode($value);

        return $json === false ? null : $json;
    }

    /**
     * @return array<mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
            if (str_contains($value, ',')) {
                return array_map('trim', explode(',', $value));
            }
            return $value !== '' ? [$value] : [];
        }
        // An iterable from context: its items, not the object's properties —
        // `(array)` turns a generator into [].
        if ($value instanceof \Traversable) {
            return self::items($value);
        }
        return (array) $value;
    }

    /**
     * The items of a Traversable, keyed by its keys while they are unique ints
     * or strings — a list as soon as one repeats (`yield from` restarts at 0)
     * or is no array key, so no item is lost to an overwrite.
     *
     * @param \Traversable<mixed, mixed> $value
     * @return array<mixed>
     */
    private static function items(\Traversable $value): array
    {
        $items = [];
        $keyed = true;

        foreach ($value as $key => $item) {
            if ($keyed && (is_int($key) || is_string($key)) && !array_key_exists($key, $items)) {
                $items[$key] = $item;
                continue;
            }
            if ($keyed) {
                $items = array_values($items);
                $keyed = false;
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * Whether the last parse rolled an impossible date or time over into a
     * real one — a warning to PHP's parser, not an error. Other warnings, such
     * as the trailing data a `+` format allows, are fine.
     *
     * @param class-string<DateTime|DateTimeImmutable> $class
     */
    private static function rolledOver(string $class): bool
    {
        $errors = $class::getLastErrors();

        return $errors !== false && array_intersect($errors['warnings'], self::ROLLOVER_WARNINGS) !== [];
    }

    /**
     * Whether a format parses no time-of-day field and has no explicit reset
     * (`!` / `|`). Only the time is reset: unparsed date fields keep PHP's
     * semantics (`m-d` is in the current year).
     */
    private static function parsesNoTime(string $format): bool
    {
        $unescaped = preg_replace('/\\\\./s', '', $format) ?? $format;

        return strpbrk($unescaped, 'aAgGhHisuvU!|') === false;
    }
}
