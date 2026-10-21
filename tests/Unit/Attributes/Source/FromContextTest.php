<?php

declare(strict_types=1);

namespace Solo\Input\Tests\Unit\Attributes\Source;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Solo\Input\Attributes\Source\FromContext;

final class FromContextTest extends TestCase
{
    public function testRejectsEmptyKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('#[FromContext] key must be a non-empty string');
        new FromContext('');
    }
}
