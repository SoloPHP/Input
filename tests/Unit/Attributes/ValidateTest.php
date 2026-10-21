<?php

declare(strict_types=1);

namespace Solo\Input\Tests\Unit\Attributes;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Solo\Input\Attributes\Validate;

final class ValidateTest extends TestCase
{
    public function testRejectsEmptyRules(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('#[Validate] rules must be a non-empty string');
        new Validate('');
    }
}
