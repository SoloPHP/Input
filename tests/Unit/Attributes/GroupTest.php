<?php

declare(strict_types=1);

namespace Solo\Input\Tests\Unit\Attributes;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Solo\Input\Attributes\Group;

final class GroupTest extends TestCase
{
    public function testRejectsEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('#[Group] name must be a non-empty string');
        new Group('');
    }

    public function testRejectsEmptyMapTo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('#[Group] mapTo must be a non-empty string or null');
        new Group('criteria', mapTo: '');
    }
}
