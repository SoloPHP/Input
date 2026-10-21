<?php

declare(strict_types=1);

namespace Solo\Input\Tests\Unit\Attributes\Source;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Solo\Input\Attributes\Source\FromRoute;

final class FromRouteTest extends TestCase
{
    public function testRejectsEmptyKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('#[FromRoute] key must be a non-empty string or null');
        new FromRoute('');
    }
}
