<?php

declare(strict_types=1);

namespace Solo\Input\Tests\Unit\Attributes;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Solo\Input\Attributes\Cast;

final class CastTest extends TestCase
{
    public function testRejectsEmptyType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('#[Cast] type must be a non-empty string');
        new Cast('');
    }
}
