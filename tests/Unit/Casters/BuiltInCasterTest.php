<?php

declare(strict_types=1);

namespace Solo\Input\Tests\Unit\Casters;

use PHPUnit\Framework\TestCase;
use Solo\Input\Casters\BuiltInCaster;
use DateTime;
use DateTimeImmutable;

final class BuiltInCasterTest extends TestCase
{
    private BuiltInCaster $caster;

    protected function setUp(): void
    {
        $this->caster = new BuiltInCaster();
    }

    public function testCastBoolAcceptsOnlyBooleanForms(): void
    {
        foreach (['true', 'TRUE', '1', 'yes', 'on', 1, true] as $input) {
            $this->assertTrue($this->caster->cast('bool', $input), var_export($input, true));
        }
        foreach (['false', '0', 'no', 'Off', 0, false] as $input) {
            $this->assertFalse($this->caster->cast('bool', $input), var_export($input, true));
        }
        foreach (['anything', 2, 'null', [true], 1.0] as $input) {
            $this->assertNull($this->caster->cast('bool', $input), var_export($input, true));
        }
    }

    public function testNumberAndStringCastsAreLossless(): void
    {
        foreach ([['42', 42], ['-7', -7], [3.0, 3]] as [$input, $expected]) {
            $this->assertSame($expected, $this->caster->cast('int', $input), var_export($input, true));
        }
        // A fraction, an exponent, an overflow or a boolean is no int.
        foreach (['1.5', 1.5, '1e3', '99999999999999999999', true] as $input) {
            $this->assertNull($this->caster->cast('int', $input), var_export($input, true));
        }
        $this->assertSame(1.5, $this->caster->cast('float', '1.5'));
        $this->assertSame(10.0, $this->caster->cast('float', '10'));
        foreach (['abc', '1e400', true] as $input) {
            $this->assertNull($this->caster->cast('float', $input), var_export($input, true));
        }
        $this->assertSame('123', $this->caster->cast('string', 123));
        foreach ([true, []] as $input) {
            $this->assertNull($this->caster->cast('string', $input), var_export($input, true));
        }
    }

    public function testCastJsonEncodesStructuredInput(): void
    {
        $this->assertSame('{"a":1}', $this->caster->cast('json', ['a' => 1]));
        $this->assertSame('[1,2]', $this->caster->cast('json', [1, 2]));
        $this->assertSame('{"x":true}', $this->caster->cast('json', '{"x":true}'));
        $this->assertSame('true', $this->caster->cast('json', true));
    }

    public function testCastArrayFromTraversableKeepsEveryItem(): void
    {
        // iterator_to_array() would keep the keys and return ['c', 'd'].
        $chained = (static function (): \Generator {
            yield from ['a', 'b'];
            yield from ['c', 'd'];
        })();
        $this->assertSame(['a', 'b', 'c', 'd'], $this->caster->cast('array', $chained));

        $repeated = (static function (): \Generator {
            yield 'k' => 1;
            yield 'k' => 2;
        })();
        $this->assertSame([1, 2], $this->caster->cast('array', $repeated));

        $objectKeys = (static function (): \Generator {
            yield new \stdClass() => 'x';
        })();
        $this->assertSame(['x'], $this->caster->cast('array', $objectKeys));
    }

    public function testCastArrayFromObject(): void
    {
        $obj = new \stdClass();
        $obj->key = 'value';
        $this->assertSame(['key' => 'value'], $this->caster->cast('array', $obj));
    }

    public function testCastNull(): void
    {
        foreach (['int', 'float', 'bool', 'string', 'array', 'json'] as $type) {
            $this->assertNull($this->caster->cast($type, null), $type);
        }
    }

    // ===== dates =====

    public function testCastDateReadsTimestamps(): void
    {
        $fromInt = $this->caster->castDate(DateTimeImmutable::class, null, 1672531200);
        $this->assertInstanceOf(DateTimeImmutable::class, $fromInt);
        $this->assertSame(1672531200, $fromInt->getTimestamp());

        // The DateTime parser rejects a bare digit string; query strings deliver one.
        $fromString = $this->caster->castDate(DateTime::class, null, '1700000000');
        $this->assertInstanceOf(DateTime::class, $fromString);
        $this->assertSame(1700000000, $fromString->getTimestamp());
    }

    public function testImpossibleDatesAreNotRolledOver(): void
    {
        // PHP's parser turns these into 2024-03-02, 2023-03-01, … with a warning.
        foreach (['2024-02-31', '2023-02-29', '20240231', '2024-01-15 24:00:00'] as $input) {
            $this->assertNull($this->caster->castDate(DateTimeImmutable::class, null, $input), $input);
        }
        $this->assertNull($this->caster->castDate(DateTimeImmutable::class, 'Y-m-d', '2024-02-31'));
        $this->assertNull($this->caster->castDate(DateTime::class, 'Y-m-d H:i', '2024-01-15 23:61'));

        $this->assertNotNull($this->caster->castDate(DateTimeImmutable::class, null, '2024-02-29'));
        $this->assertNotNull($this->caster->castDate(DateTimeImmutable::class, 'd.m.Y', '29.02.2024'));
        // A `+` format allows trailing data — a warning, but no rolled-over date.
        $this->assertNotNull($this->caster->castDate(DateTimeImmutable::class, 'Y-m-d+', '2024-01-15 extra'));
    }

    public function testFormatAppliesToANumberToo(): void
    {
        $compact = $this->caster->castDate(DateTimeImmutable::class, 'Ymd', 20240115);
        $this->assertInstanceOf(DateTimeImmutable::class, $compact);
        $this->assertSame('2024-01-15 00:00:00', $compact->format('Y-m-d H:i:s'));

        $timestamp = $this->caster->castDate(DateTimeImmutable::class, 'U', 1700000000);
        $this->assertInstanceOf(DateTimeImmutable::class, $timestamp);
        $this->assertSame(1700000000, $timestamp->getTimestamp());

        // Not a timestamp behind the format's back.
        $this->assertNull($this->caster->castDate(DateTimeImmutable::class, 'Y-m-d', 1700000000));
    }

    public function testCastDateConvertsBetweenDateClasses(): void
    {
        $mutable = new DateTime('2024-01-15 10:30:00');
        $immutable = $this->caster->castDate(DateTimeImmutable::class, null, $mutable);
        $this->assertInstanceOf(DateTimeImmutable::class, $immutable);
        $this->assertSame($mutable->getTimestamp(), $immutable->getTimestamp());

        $back = $this->caster->castDate(DateTime::class, null, $immutable);
        $this->assertInstanceOf(DateTime::class, $back);

        $same = new DateTimeImmutable('2024-01-15');
        $this->assertSame($same, $this->caster->castDate(DateTimeImmutable::class, null, $same));
    }

    // ===== enums =====

    public function testCastIntBackedEnumAcceptsCasesAndNumericStrings(): void
    {
        // A case, as the application passes it in context, is kept.
        $case = CasterTestLevel::High;
        $this->assertSame($case, $this->caster->castEnum(CasterTestLevel::class, $case));
        $this->assertSame(CasterTestLevel::High, $this->caster->castEnum(CasterTestLevel::class, '2'));
        $this->assertSame(CasterTestLevel::High, $this->caster->castEnum(CasterTestLevel::class, 2));
        // Like an int property: a whole float is the int, a fraction is not.
        $this->assertSame(CasterTestLevel::High, $this->caster->castEnum(CasterTestLevel::class, 2.0));
        $this->assertNull($this->caster->castEnum(CasterTestLevel::class, 2.5));
        $this->assertNull($this->caster->castEnum(CasterTestLevel::class, '2.5'));
        $this->assertNull($this->caster->castEnum(CasterTestLevel::class, true));
    }

    public function testBlankIsWhatEachCastReadsAnEmptyStringAs(): void
    {
        foreach ([...BuiltInCaster::CAST_TYPES, 'iterable'] as $type) {
            $this->assertArrayHasKey($type, BuiltInCaster::BLANK, $type);
            $this->assertSame($this->caster->cast($type, ''), BuiltInCaster::BLANK[$type], $type);
        }
    }
}

enum CasterTestLevel: int
{
    case Low = 1;
    case High = 2;
}
