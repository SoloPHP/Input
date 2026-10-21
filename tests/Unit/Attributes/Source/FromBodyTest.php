<?php

declare(strict_types=1);

namespace Solo\Input\Tests\Unit\Attributes\Source;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Solo\Input\Attributes\Source\FromBody;

final class FromBodyTest extends TestCase
{
    public function testRejectsEmptyPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FromBody('');
    }

    public function testRejectsMalformedPath(): void
    {
        // Leading dot, trailing dot, and empty segment are all malformed —
        // one representative case covers the same guard.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('malformed');
        new FromBody('a..b');
    }
}
